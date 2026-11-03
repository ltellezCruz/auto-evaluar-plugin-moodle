"""
main.py
Servidor FastAPI que actúa como puente entre Moodle (el botón "Auto
Evaluar" del plugin assignfeedback_aiocr) y Gemini.

Pipeline del endpoint /ia/generar-retroalimentacion:
    1. Comprueba la clave del servicio (cabecera X-Aiocr-Key).
    2. Descarga el archivo de la entrega (solo desde ALLOWED_FILE_HOSTS,
       solo /webservice/pluginfile.php, PDF o imagen, hasta MAX_FILE_MB).
    3. Lo convierte a imagen, UNA por cada página (hasta MAX_PAGES).
    4. PASO 1 de Gemini, por cada página: transcribe (prompts/extraer.txt).
    5. Si alguna página falló la transcripción, se detiene aquí y se
       informa el error (no se genera una evaluación incompleta).
    6. PASO 2 de Gemini: evalúa la transcripción completa (prompts/evaluar.txt).
    7. Guarda transcripción y metadatos (ver transcripciones.py) y devuelve
       el texto de retroalimentación al plugin.

Los prompts viven solo en el servidor: quien llama al servicio envía
datos (qué archivo evaluar), nunca instrucciones para Gemini.

Ejecución (desde fastapi-service/):
    python -m uvicorn app.main:app --host 127.0.0.1 --port 8000
"""

import hmac
import logging
import tempfile
import threading
import time
from datetime import datetime
from typing import Optional

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from . import __version__
from .config import cargar_config, leer_prompt
from .gemini_client import consultar_gemini
from .moodle_client import descargar_archivo_moodle, llamar_moodle
from .pdf import convertir_pdf_a_imagenes
from .transcripciones import guardar_evaluacion, limpiar_antiguas

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("aiocr")

config = cargar_config()
PROMPT_EXTRAER = leer_prompt(config, "extraer.txt")
PROMPT_EVALUAR_BASE = leer_prompt(config, "evaluar.txt")

log.info("Auto Evaluar %s", __version__)
log.info("Moodle: %s | modelo: %s | hosts permitidos: %s | transcripciones: %s",
         config.moodle_url, config.gemini_model,
         ", ".join(sorted(config.allowed_file_hosts)),
         config.output_dir if config.save_transcriptions else "no se guardan")
log.info("Límites: %s MB por archivo, %s páginas, %s evaluaciones simultáneas, retención %s días",
         config.max_file_mb, config.max_pages, config.max_concurrent,
         config.transcriptions_days or "sin límite")
limpiar_antiguas(config, forzar=True)

# /docs y /openapi.json describen toda la API: solo si ENABLE_DOCS=true.
app = FastAPI(
    title="Auto Evaluar - servicio FastAPI",
    version=__version__,
    docs_url="/docs" if config.enable_docs else None,
    redoc_url=None,
    openapi_url="/openapi.json" if config.enable_docs else None,
)

# Evaluaciones en curso al mismo tiempo. Las que superan el límite se
# rechazan de inmediato (503) en vez de acumularse en memoria.
_cupos = threading.BoundedSemaphore(config.max_concurrent)


def verificar_clave(x_aiocr_key: Optional[str] = Header(default=None)):
    """Solo el plugin (que conoce SERVICE_API_KEY) puede usar el servicio."""
    if not x_aiocr_key or not hmac.compare_digest(x_aiocr_key, config.service_api_key):
        raise HTTPException(status_code=401, detail="Clave del servicio ausente o incorrecta (X-Aiocr-Key).")


# --- Endpoints de diagnóstico ---

@app.get("/")
def raiz():
    return {"mensaje": "Servidor FastAPI corriendo correctamente", "version": __version__}


@app.get("/moodle/info", dependencies=[Depends(verificar_clave)])
def info_moodle():
    """Comprueba el token y la conexión con Moodle."""
    datos = llamar_moodle(config, "core_webservice_get_site_info")
    return {k: datos.get(k) for k in ("sitename", "siteurl", "username", "release")}


# --- Pipeline: descarga + varias páginas + Gemini (2 pasos) ---

class ArchivoEntrega(BaseModel):
    filename: str = Field(max_length=255)
    fileurl: str = Field(max_length=2048)
    mimetype: str = Field(default="", max_length=255)


class SolicitudRetroalimentacion(BaseModel):
    assignmentid: int = Field(gt=0)
    userid: int = Field(gt=0)
    username: Optional[str] = Field(default=None, max_length=100)
    files: list[ArchivoEntrega] = Field(default=[], max_length=20)
    onlinetext: Optional[str] = Field(default=None, max_length=100_000)


@app.post("/ia/generar-retroalimentacion", dependencies=[Depends(verificar_clave)])
def generar_retroalimentacion(
    solicitud: SolicitudRetroalimentacion,
    x_aiocr_plugin_version: Optional[str] = Header(default=None, max_length=20),
):
    """
    Endpoint que llama el plugin de Moodle cuando el profesor le da clic
    a "Auto Evaluar" sobre la entrega de UN estudiante específico.
    """
    if not solicitud.files:
        return {
            "feedback_text": "",
            "success": False,
            "error": "La entrega no tiene archivos adjuntos para procesar.",
        }

    if not _cupos.acquire(blocking=False):
        raise HTTPException(
            status_code=503,
            detail=f"El servicio ya está procesando {config.max_concurrent} evaluaciones. "
                   "Intenta de nuevo en un momento.",
        )
    try:
        # Fuera de una petición HTTP (pruebas), el valor por defecto es el
        # marcador Header(...) y no un texto.
        version_plugin = x_aiocr_plugin_version if isinstance(x_aiocr_plugin_version, str) else None
        return _evaluar(solicitud, version_plugin)
    finally:
        _cupos.release()


def _evaluar(solicitud: SolicitudRetroalimentacion, version_plugin: Optional[str] = None) -> dict:
    inicio = time.monotonic()
    primer_archivo = solicitud.files[0]
    registro = {
        "fecha": datetime.now().isoformat(timespec="seconds"),
        # Con qué versiones se produjo esta evaluación (útil al comparar
        # resultados entre versiones del plugin, del servicio o de los prompts).
        "version_servicio": __version__,
        "version_plugin": version_plugin,
        "assignmentid": solicitud.assignmentid,
        "userid": solicitud.userid,
        "archivo": primer_archivo.filename,
        "modelo": config.gemini_model,
        "dpi": config.pdf_dpi,
        "paginas": 0,
        "llamadas": [],
        "resultado": "error",
        "error": None,
    }
    transcripcion_completa = ""

    try:
        # El archivo y sus imágenes se borran al terminar la solicitud.
        with tempfile.TemporaryDirectory(prefix="aiocr_") as carpeta_temporal:

            # --- Descargar el archivo real de la entrega ---
            try:
                ruta = descargar_archivo_moodle(config, primer_archivo.fileurl, carpeta_temporal)
            except Exception as e:
                raise HTTPException(status_code=502, detail=f"No se pudo descargar el archivo desde Moodle: {e}")

            # --- Convertir TODAS las páginas a imagen ---
            try:
                if ruta.endswith(".pdf"):
                    imagenes = convertir_pdf_a_imagenes(ruta, config.pdf_dpi, config.max_pages)
                else:
                    imagenes = [ruta]
            except ValueError as e:
                raise HTTPException(status_code=422, detail=str(e))
            except Exception as e:
                raise HTTPException(status_code=500, detail=f"Error al convertir el PDF a imagen: {e}")
            registro["paginas"] = len(imagenes)

            # --- PASO 1: transcribir cada página con Gemini ---
            transcripciones = []
            for numero_pagina, imagen in enumerate(imagenes, start=1):
                pagina = {"archivo": primer_archivo.filename, "pagina": numero_pagina, "texto": "", "error": None}
                try:
                    texto, consumo = consultar_gemini(config, PROMPT_EXTRAER, imagen)
                    registro["llamadas"].append({"paso": "transcripcion", "pagina": numero_pagina, **consumo})
                    if not texto.strip():
                        raise ValueError("La transcripción está vacía.")
                    pagina["texto"] = texto
                except Exception as e:
                    pagina["error"] = str(e)
                transcripciones.append(pagina)

        transcripcion_completa = "\n\n".join(
            f"Archivo: {r['archivo']} | Página: {r['pagina']}\n{r['texto']}"
            for r in transcripciones if r["texto"]
        )

        # Si alguna página falló, no seguimos: no se publica una evaluación
        # incompleta.
        pendientes = [r for r in transcripciones if r["error"] or not r["texto"].strip()]
        if pendientes:
            detalle = "; ".join(f"{r['archivo']} pág.{r['pagina']}: {r['error']}" for r in pendientes)
            registro["error"] = f"No se pudo transcribir alguna página: {detalle}"
            return {"feedback_text": "", "success": False, "error": registro["error"]}

        # --- PASO 2: evaluar la transcripción completa ---
        try:
            retroalimentacion, consumo = consultar_gemini(config, PROMPT_EVALUAR_BASE + transcripcion_completa)
            registro["llamadas"].append({"paso": "evaluacion", **consumo})
        except Exception as e:
            raise HTTPException(status_code=502, detail=f"Error al generar la retroalimentación con Gemini: {e}")

        registro["resultado"] = "ok"
        return {"feedback_text": retroalimentacion, "success": True}

    except HTTPException as e:
        registro["error"] = e.detail
        raise
    finally:
        registro["duracion_s"] = round(time.monotonic() - inicio, 2)
        registro["tokens_total"] = sum(ll.get("total", 0) for ll in registro["llamadas"])
        guardar_evaluacion(config, registro, transcripcion_completa)
