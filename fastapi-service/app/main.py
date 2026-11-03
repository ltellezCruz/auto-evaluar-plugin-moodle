"""
main.py
Servidor FastAPI que actúa como puente entre Moodle (el botón "Auto
Evaluar" del plugin assignfeedback_aiocr) y Gemini.

Pipeline del endpoint /ia/generar-retroalimentacion:
    1. Recibe la fileurl de la entrega de UN estudiante específico.
    2. Descarga el PDF real de esa entrega (solo desde ALLOWED_FILE_HOSTS).
    3. Lo convierte a imagen, UNA por cada página.
    4. PASO 1 de Gemini, por cada página: transcribe (prompts/extraer.txt).
    5. Si alguna página falló la transcripción, se detiene aquí y se
       informa el error (no se genera una evaluación incompleta).
    6. PASO 2 de Gemini: evalúa la transcripción completa (prompts/evaluar.txt).
    7. Devuelve el texto de retroalimentación al plugin, que lo escribe
       en "Comentarios de retroalimentación".

Ejecución (desde fastapi-service/):
    python -m uvicorn app.main:app --host 127.0.0.1 --port 8000
"""

import logging
import os
import tempfile
from typing import Optional

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

from .config import cargar_config, leer_prompt
from .gemini_client import consultar_gemini
from .moodle_client import descargar_archivo_moodle, llamar_moodle
from .pdf import convertir_pdf_a_imagenes

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("aiocr")

config = cargar_config()
PROMPT_EXTRAER = leer_prompt(config, "extraer.txt")
PROMPT_EVALUAR_BASE = leer_prompt(config, "evaluar.txt")

log.info("Moodle: %s | modelo: %s | hosts permitidos: %s | transcripciones: %s",
         config.moodle_url, config.gemini_model,
         ", ".join(sorted(config.allowed_file_hosts)), config.output_dir)

app = FastAPI(title="Auto Evaluar - servicio FastAPI")


# --- Endpoints de diagnóstico ---

@app.get("/")
def raiz():
    return {"mensaje": "Servidor FastAPI corriendo correctamente"}


@app.get("/moodle/info")
def info_moodle():
    return llamar_moodle(config, "core_webservice_get_site_info")


@app.get("/moodle/cursos")
def listar_cursos():
    return llamar_moodle(config, "core_course_get_courses_by_field")


# --- Pipeline: descarga + varias páginas + Gemini (2 pasos) ---

class ArchivoEntrega(BaseModel):
    filename: str
    fileurl: str
    mimetype: str


class SolicitudRetroalimentacion(BaseModel):
    assignmentid: int
    userid: int
    username: Optional[str] = None
    files: list[ArchivoEntrega] = []
    onlinetext: Optional[str] = None


@app.post("/ia/generar-retroalimentacion")
def generar_retroalimentacion(solicitud: SolicitudRetroalimentacion):
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

    username = solicitud.username or f"userid_{solicitud.userid}"
    primer_archivo = solicitud.files[0]

    # El PDF y sus imágenes se borran al terminar la solicitud.
    with tempfile.TemporaryDirectory(prefix="aiocr_") as carpeta_temporal:

        # --- Descargar el PDF real de la entrega ---
        try:
            ruta_pdf = descargar_archivo_moodle(
                config, primer_archivo.fileurl, primer_archivo.filename, carpeta_temporal
            )
        except Exception as e:
            raise HTTPException(status_code=502, detail=f"No se pudo descargar el archivo desde Moodle: {e}")

        # --- Convertir TODAS las páginas a imagen ---
        try:
            if ruta_pdf.lower().endswith(".pdf"):
                imagenes = convertir_pdf_a_imagenes(ruta_pdf, config.pdf_dpi)
            else:
                imagenes = [ruta_pdf]
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"Error al convertir el PDF a imagen: {e}")

        # --- PASO 1: transcribir cada página con Gemini ---
        transcripciones = []
        for numero_pagina, imagen in enumerate(imagenes, start=1):
            registro = {
                "archivo": primer_archivo.filename,
                "pagina": numero_pagina,
                "texto": "",
                "error": None,
            }
            try:
                texto = consultar_gemini(config, PROMPT_EXTRAER, imagen)
                if not texto.strip():
                    raise ValueError("La transcripción está vacía.")
                registro["texto"] = texto
            except Exception as e:
                registro["error"] = str(e)
            transcripciones.append(registro)

    # Si alguna página falló, no seguimos: no se publica una evaluación
    # incompleta.
    pendientes = [r for r in transcripciones if r["error"] or not r["texto"].strip()]
    if pendientes:
        detalle = "; ".join(f"{r['archivo']} pág.{r['pagina']}: {r['error']}" for r in pendientes)
        return {
            "feedback_text": "",
            "success": False,
            "error": f"No se pudo transcribir alguna página: {detalle}",
        }

    # Guardamos la transcripción completa como registro/auditoría.
    transcripcion_completa = "\n\n".join(
        f"Archivo: {r['archivo']} | Página: {r['pagina']}\n{r['texto']}"
        for r in transcripciones
    )
    try:
        os.makedirs(config.output_dir, exist_ok=True)
        with open(config.output_dir / f"{username}.txt", "w", encoding="utf-8") as f:
            f.write(transcripcion_completa)
    except Exception:
        # el guardado de auditoría no debe tumbar la respuesta al plugin
        log.exception("No se pudo guardar la transcripción de %s", username)

    # --- PASO 2: evaluar la transcripción completa ---
    try:
        prompt_completo = PROMPT_EVALUAR_BASE + transcripcion_completa
        retroalimentacion = consultar_gemini(config, prompt_completo)
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"Error al generar la retroalimentación con Gemini: {e}")

    return {
        "feedback_text": retroalimentacion,
        "success": True,
    }
