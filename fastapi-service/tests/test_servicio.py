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
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

CARPETA_SERVICIO = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(CARPETA_SERVICIO))

import pymupdf  # noqa: E402

TOKEN = "token_de_prueba"
CLAVE = "clave_de_prueba"


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
        cls.salida = Path(tempfile.mkdtemp(prefix="aiocr_pruebas_"))

        os.environ.update(
            MOODLE_URL=f"http://127.0.0.1:{cls.puerto}",
            MOODLE_TOKEN=TOKEN,
            GEMINI_API_KEY=CLAVE,
            OUTPUT_DIR=str(cls.salida),
        )
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
        )

    @classmethod
    def tearDownClass(cls):
        cls.servidor.shutdown()
        shutil.rmtree(cls.salida, ignore_errors=True)

    def setUp(self):
        MoodleYGeminiFalsos.peticiones = []
        MoodleYGeminiFalsos.pdf = crear_pdf(2)
        self.main.config = self.config_base

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

        transcripcion = (self.salida / "est_prueba.txt").read_text(encoding="utf-8")
        self.assertEqual(transcripcion.count("TRANSCRIPCION FALSA"), 2)
        prompt_final = posts[2][2]["contents"][0]["parts"][0]["text"]
        self.assertEqual(prompt_final, self.main.PROMPT_EVALUAR_BASE + transcripcion)

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


if __name__ == "__main__":
    unittest.main()
