"""
Pruebas del servicio con Moodle y Gemini simulados (servidor HTTP local).
No usan internet ni gastan cuota de Gemini.

Desde fastapi-service/:
    python -m unittest discover -s tests -v
"""

import dataclasses
import glob
import json
import os
import shutil
import socket
import sys
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

CARPETA_SERVICIO = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(CARPETA_SERVICIO))

import pymupdf  # noqa: E402

TOKEN = "token_de_prueba"
CLAVE = "clave_de_prueba"
CLAVE_SERVICIO = "clave_del_servicio_de_prueba"
SALIDA = Path(tempfile.mkdtemp(prefix="aiocr_pruebas_"))

# Antes de importar app.main (lee la configuración al cargarse). Así las
# pruebas no dependen del .env real.
os.environ.update(
    MOODLE_URL="http://127.0.0.1:9",
    MOODLE_TOKEN=TOKEN,
    GEMINI_API_KEY=CLAVE,
    SERVICE_API_KEY=CLAVE_SERVICIO,
    OUTPUT_DIR=str(SALIDA),
)


def crear_pdf(paginas: int) -> bytes:
    doc = pymupdf.open()
    for i in range(paginas):
        doc.new_page().insert_text((72, 72), f"Ejercicio pagina {i + 1}: 2+2=5")
    return doc.tobytes()


class MoodleYGeminiFalsos(BaseHTTPRequestHandler):
    """GET = Moodle (descarga de archivos). POST = Gemini."""

    peticiones = []
    pdf = b""

    def log_message(self, *args):
        pass

    def _responder(self, codigo, tipo, cuerpo: bytes):
        self.send_response(codigo)
        self.send_header("Content-Type", tipo)
        self.send_header("Content-Length", str(len(cuerpo)))
        self.end_headers()
        self.wfile.write(cuerpo)

    def do_GET(self):
        self.peticiones.append(("GET", self.path, self.headers.get("Host")))
        if f"token={TOKEN}" not in self.path:
            error = {"error": "Ficha (token) no válida - ficha no encontrada"}
            self._responder(200, "application/json", json.dumps(error).encode())
        elif "/texto" in self.path:
            self._responder(200, "text/plain", b"no es un pdf")
        else:
            self._responder(200, "application/pdf", self.pdf)

    def do_POST(self):
        cuerpo = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        self.peticiones.append(("POST", self.path, cuerpo, self.headers.get("x-goog-api-key")))
        con_imagen = any("inline_data" in p for p in cuerpo["contents"][0]["parts"])
        texto = "TRANSCRIPCION FALSA" if con_imagen else "<div>RETRO FALSA</div>"
        respuesta = {
            "candidates": [{"content": {"parts": [{"text": texto}]}}],
            "usageMetadata": {"promptTokenCount": 10, "candidatesTokenCount": 5, "totalTokenCount": 15},
        }
        self._responder(200, "application/json", json.dumps(respuesta).encode())


class PruebasServicio(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.servidor = HTTPServer(("127.0.0.1", 0), MoodleYGeminiFalsos)
        cls.puerto = cls.servidor.server_address[1]
        threading.Thread(target=cls.servidor.serve_forever, daemon=True).start()
        cls.salida = SALIDA
        import app.main as main
        cls.main = main
        # Valores fijos: las pruebas no dependen de lo que tenga el .env real.
        cls.config_base = dataclasses.replace(
            main.config,
            moodle_url=f"http://127.0.0.1:{cls.puerto}",
            moodle_public_url="",
            moodle_token=TOKEN,
            allowed_file_hosts=frozenset({"127.0.0.1", "localhost"}),
            gemini_api_key=CLAVE,
            gemini_url=f"http://127.0.0.1:{cls.puerto}/gemini",
            output_dir=cls.salida,
            service_api_key=CLAVE_SERVICIO,
            max_file_mb=20,
            max_pages=20,
            save_transcriptions=True,
            transcriptions_days=180,
        )

    @classmethod
    def tearDownClass(cls):
        cls.servidor.shutdown()
        shutil.rmtree(cls.salida, ignore_errors=True)

    def setUp(self):
        MoodleYGeminiFalsos.peticiones = []
        MoodleYGeminiFalsos.pdf = crear_pdf(2)
        self.main.config = self.config_base
        shutil.rmtree(self.salida, ignore_errors=True)
        self.salida.mkdir()

    def posts(self):
        return [p for p in MoodleYGeminiFalsos.peticiones if p[0] == "POST"]

    def registros(self):
        return [json.loads(p.read_text(encoding="utf-8")) for p in sorted(self.salida.rglob("*.json"))]

    def solicitud(self, url, username="est_prueba"):
        return self.main.SolicitudRetroalimentacion(
            assignmentid=1, userid=7, username=username,
            files=[self.main.ArchivoEntrega(filename="tarea.pdf", fileurl=url, mimetype="application/pdf")],
        )

    def url_moodle(self, base=None):
        return f"{base or f'http://127.0.0.1:{self.puerto}'}/webservice/pluginfile.php/1/a/tarea.pdf"

    def test_prompts_cargados(self):
        self.assertIn("CONTEXTO DEL PROFESOR", self.main.PROMPT_EXTRAER)
        # La transcripción se pega al final del prompt de evaluación.
        self.assertTrue(self.main.PROMPT_EVALUAR_BASE.endswith("\n\n"))

    def test_pipeline_completo(self):
        temporales_antes = set(glob.glob(os.path.join(tempfile.gettempdir(), "aiocr_*")))

        r = self.main.generar_retroalimentacion(self.solicitud(self.url_moodle()))

        self.assertEqual(r, {"feedback_text": "<div>RETRO FALSA</div>", "success": True})
        gets = [p for p in MoodleYGeminiFalsos.peticiones if p[0] == "GET"]
        posts = [p for p in MoodleYGeminiFalsos.peticiones if p[0] == "POST"]
        self.assertEqual(len(gets), 1)
        self.assertTrue(gets[0][1].endswith(f"?token={TOKEN}"))
        self.assertEqual(len(posts), 3, "2 páginas + 1 evaluación")
        self.assertTrue(all(p[3] == CLAVE for p in posts))

        # Transcripción y metadatos en tarea_<id>/usuario_<id>_<fecha>, sin el nombre de usuario.
        txts = list((self.salida / "tarea_1").glob("usuario_7_*.txt"))
        self.assertEqual(len(txts), 1)
        transcripcion = txts[0].read_text(encoding="utf-8")
        self.assertEqual(transcripcion.count("TRANSCRIPCION FALSA"), 2)
        prompt_final = posts[2][2]["contents"][0]["parts"][0]["text"]
        self.assertEqual(prompt_final, self.main.PROMPT_EVALUAR_BASE + transcripcion)
        registro = self.registros()[0]
        self.assertEqual(registro["resultado"], "ok")
        self.assertEqual(registro["paginas"], 2)
        self.assertEqual([ll["paso"] for ll in registro["llamadas"]], ["transcripcion", "transcripcion", "evaluacion"])
        self.assertEqual(registro["tokens_total"], 45)
        self.assertNotIn("est_prueba", json.dumps(registro))

        temporales_despues = set(glob.glob(os.path.join(tempfile.gettempdir(), "aiocr_*")))
        self.assertEqual(temporales_despues - {str(self.salida)}, temporales_antes - {str(self.salida)})

    def test_host_no_permitido_no_envia_token(self):
        from fastapi import HTTPException
        with self.assertRaises(HTTPException) as ctx:
            self.main.generar_retroalimentacion(self.solicitud("http://otro-sitio.example/archivo.pdf"))
        self.assertEqual(ctx.exception.status_code, 502)
        self.assertIn("ALLOWED_FILE_HOSTS", ctx.exception.detail)
        self.assertEqual(MoodleYGeminiFalsos.peticiones, [])

    def test_url_publica_se_descarga_por_dentro(self):
        # Moodle con wwwroot público distinto de la dirección interna (Docker, ngrok).
        self.main.config = dataclasses.replace(self.config_base, moodle_public_url="https://moodle.publico.example")

        r = self.main.generar_retroalimentacion(self.solicitud(self.url_moodle("https://moodle.publico.example")))

        self.assertTrue(r["success"])
        get = [p for p in MoodleYGeminiFalsos.peticiones if p[0] == "GET"][0]
        self.assertEqual(get[2], "moodle.publico.example", "debe presentarse con el host público")

    def test_error_de_moodle_no_se_trata_como_pdf(self):
        from fastapi import HTTPException
        self.main.config = dataclasses.replace(self.config_base, moodle_token="token_incorrecto")
        with self.assertRaises(HTTPException) as ctx:
            self.main.generar_retroalimentacion(self.solicitud(self.url_moodle()))
        self.assertIn("Ficha (token) no válida", ctx.exception.detail)
        self.assertFalse([p for p in MoodleYGeminiFalsos.peticiones if p[0] == "POST"], "no debe llamar a Gemini")

    def test_entrega_sin_archivos(self):
        r = self.main.generar_retroalimentacion(
            self.main.SolicitudRetroalimentacion(assignmentid=1, userid=7)
        )
        self.assertFalse(r["success"])

    def test_cada_evaluacion_se_guarda_aparte(self):
        self.main.generar_retroalimentacion(self.solicitud(self.url_moodle()))
        self.main.generar_retroalimentacion(self.solicitud(self.url_moodle()))
        self.assertEqual(len(list(self.salida.rglob("*.txt"))), 2)
        self.assertEqual(len(self.registros()), 2)

    def test_no_guardar_transcripciones(self):
        self.main.config = dataclasses.replace(self.config_base, save_transcriptions=False)
        self.assertTrue(self.main.generar_retroalimentacion(self.solicitud(self.url_moodle()))["success"])
        self.assertEqual(list(self.salida.rglob("*")), [])

    def test_ruta_que_no_es_de_archivos_no_recibe_token(self):
        from fastapi import HTTPException
        url = f"http://127.0.0.1:{self.puerto}/webservice/rest/server.php?wsfunction=core_user_get_users"
        with self.assertRaises(HTTPException) as ctx:
            self.main.generar_retroalimentacion(self.solicitud(url))
        self.assertIn("no es un archivo de Moodle", ctx.exception.detail)
        self.assertEqual(MoodleYGeminiFalsos.peticiones, [])

    def test_tipo_de_archivo_no_admitido(self):
        from fastapi import HTTPException
        with self.assertRaises(HTTPException) as ctx:
            self.main.generar_retroalimentacion(self.solicitud(self.url_moodle() + "/texto"))
        self.assertIn("Tipo de archivo no admitido", ctx.exception.detail)
        self.assertEqual(self.posts(), [])

    def test_archivo_demasiado_grande(self):
        from fastapi import HTTPException
        self.main.config = dataclasses.replace(self.config_base, max_file_mb=0)
        with self.assertRaises(HTTPException) as ctx:
            self.main.generar_retroalimentacion(self.solicitud(self.url_moodle()))
        self.assertIn("máximo", ctx.exception.detail)
        self.assertEqual(self.posts(), [])

    def test_demasiadas_paginas_no_llama_a_gemini(self):
        from fastapi import HTTPException
        self.main.config = dataclasses.replace(self.config_base, max_pages=1)
        with self.assertRaises(HTTPException) as ctx:
            self.main.generar_retroalimentacion(self.solicitud(self.url_moodle()))
        self.assertEqual(ctx.exception.status_code, 422)
        self.assertIn("2 páginas", ctx.exception.detail)
        self.assertEqual(self.posts(), [])
        self.assertEqual(self.registros()[0]["resultado"], "error")

    def test_limite_de_evaluaciones_simultaneas(self):
        from fastapi import HTTPException
        tomados = 0
        while self.main._cupos.acquire(blocking=False):
            tomados += 1
        try:
            with self.assertRaises(HTTPException) as ctx:
                self.main.generar_retroalimentacion(self.solicitud(self.url_moodle()))
            self.assertEqual(ctx.exception.status_code, 503)
        finally:
            for _ in range(tomados):
                self.main._cupos.release()
        self.assertEqual(MoodleYGeminiFalsos.peticiones, [])

    def test_retencion_borra_lo_antiguo(self):
        from app.transcripciones import limpiar_antiguas
        carpeta = self.salida / "tarea_9"
        carpeta.mkdir()
        viejo, nuevo = carpeta / "usuario_1_viejo.txt", carpeta / "usuario_1_nuevo.txt"
        viejo.write_text("x")
        nuevo.write_text("y")
        hace_200_dias = time.time() - 200 * 86400
        os.utime(viejo, (hace_200_dias, hace_200_dias))
        self.assertEqual(limpiar_antiguas(self.config_base, forzar=True), 1)
        self.assertFalse(viejo.exists())
        self.assertTrue(nuevo.exists())


class PruebasHTTP(unittest.TestCase):
    """La autenticación se prueba con el servidor real (uvicorn) en un hilo."""

    @classmethod
    def setUpClass(cls):
        import requests
        import uvicorn
        import app.main as main
        cls.requests = requests
        with socket.socket() as s:
            s.bind(("127.0.0.1", 0))
            cls.puerto = s.getsockname()[1]
        cls.servidor = uvicorn.Server(uvicorn.Config(main.app, host="127.0.0.1", port=cls.puerto, log_level="warning"))
        threading.Thread(target=cls.servidor.run, daemon=True).start()
        for _ in range(50):
            if cls.servidor.started:
                break
            time.sleep(0.1)
        cls.base = f"http://127.0.0.1:{cls.puerto}"

    @classmethod
    def tearDownClass(cls):
        cls.servidor.should_exit = True

    def post(self, cabeceras, cuerpo=None):
        cuerpo = cuerpo or {"assignmentid": 1, "userid": 7, "files": []}
        return self.requests.post(f"{self.base}/ia/generar-retroalimentacion", json=cuerpo,
                                  headers=cabeceras, timeout=5)

    def test_raiz_sin_clave(self):
        self.assertEqual(self.requests.get(self.base + "/", timeout=5).status_code, 200)

    def test_sin_clave_401(self):
        self.assertEqual(self.post({}).status_code, 401)

    def test_clave_incorrecta_401(self):
        self.assertEqual(self.post({"X-Aiocr-Key": "otra"}).status_code, 401)

    def test_moodle_info_requiere_clave(self):
        self.assertEqual(self.requests.get(self.base + "/moodle/info", timeout=5).status_code, 401)

    def test_clave_correcta(self):
        r = self.post({"X-Aiocr-Key": CLAVE_SERVICIO})
        self.assertEqual(r.status_code, 200)
        self.assertFalse(r.json()["success"])  # entrega sin archivos

    def test_validacion_de_la_solicitud(self):
        r = self.post({"X-Aiocr-Key": CLAVE_SERVICIO}, {"assignmentid": -1, "userid": 7})
        self.assertEqual(r.status_code, 422)

    def test_docs_apagado(self):
        self.assertEqual(self.requests.get(self.base + "/docs", timeout=5).status_code, 404)
        self.assertEqual(self.requests.get(self.base + "/openapi.json", timeout=5).status_code, 404)


if __name__ == "__main__":
    unittest.main()
