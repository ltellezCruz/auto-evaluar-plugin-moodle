"""
Pruebas de scripts/deploy_plugin.py y scripts/package_plugin.py contra un
Moodle simulado en una carpeta temporal.

Desde la raíz del repositorio:
    python -m unittest discover -s tests -v
"""

import importlib.util
import io
import shutil
import tempfile
import unittest
import zipfile
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

RAIZ_REPO = Path(__file__).resolve().parent.parent


def cargar(nombre):
    spec = importlib.util.spec_from_file_location(nombre, RAIZ_REPO / "scripts" / f"{nombre}.py")
    modulo = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(modulo)
    return modulo


deploy = cargar("deploy_plugin")
package = cargar("package_plugin")


def ejecutar(funcion, args):
    salida = io.StringIO()
    with redirect_stdout(salida), redirect_stderr(salida):
        codigo = funcion(args)
    return codigo, salida.getvalue()


class PruebasDespliegue(unittest.TestCase):

    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="aiocr_deploy_"))
        self.moodle = self.tmp / "moodle"
        (self.moodle / "lib").mkdir(parents=True)
        (self.moodle / "mod" / "assign" / "feedback").mkdir(parents=True)
        (self.moodle / "version.php").write_text("<?php")
        (self.moodle / "lib" / "moodlelib.php").write_text("<?php")
        self.destino = self.moodle / "mod" / "assign" / "feedback" / "aiocr"
        # Los respaldos van a una carpeta temporal, no a dist/ del repositorio.
        self.respaldos_originales = deploy.CARPETA_RESPALDOS
        deploy.CARPETA_RESPALDOS = self.tmp / "respaldos"

    def tearDown(self):
        deploy.CARPETA_RESPALDOS = self.respaldos_originales
        shutil.rmtree(self.tmp, ignore_errors=True)

    def archivos(self, carpeta):
        return sorted(p.relative_to(carpeta).as_posix() for p in carpeta.rglob("*") if p.is_file())

    def test_primera_copia_identica_al_repositorio(self):
        codigo, _ = ejecutar(deploy.main, ["--moodle-dir", str(self.moodle)])
        self.assertEqual(codigo, 0)
        self.assertEqual(self.archivos(self.destino), self.archivos(deploy.ORIGEN))

    def test_segunda_copia_respalda_y_quita_archivos_viejos(self):
        ejecutar(deploy.main, ["--moodle-dir", str(self.moodle)])
        (self.destino / "amd").mkdir()
        (self.destino / "amd" / "viejo.js").write_text("//")

        codigo, _ = ejecutar(deploy.main, ["--moodle-dir", str(self.moodle)])

        self.assertEqual(codigo, 0)
        self.assertFalse((self.destino / "amd").exists())
        respaldos = list((self.tmp / "respaldos").iterdir())
        self.assertEqual(len(respaldos), 1)
        self.assertTrue((respaldos[0] / "amd" / "viejo.js").exists())

    def test_no_borra_una_carpeta_ajena(self):
        self.destino.mkdir()
        (self.destino / "otro.txt").write_text("x")
        codigo, salida = ejecutar(deploy.main, ["--moodle-dir", str(self.moodle)])
        self.assertEqual(codigo, 1)
        self.assertIn("no se borrará", salida)
        self.assertTrue((self.destino / "otro.txt").exists())

    def test_rechaza_carpeta_que_no_es_moodle(self):
        codigo, salida = ejecutar(deploy.main, ["--moodle-dir", str(self.tmp)])
        self.assertEqual(codigo, 1)
        self.assertIn("no parece la carpeta de código de Moodle", salida)

    def test_dry_run_no_cambia_nada(self):
        codigo, _ = ejecutar(deploy.main, ["--moodle-dir", str(self.moodle), "--dry-run", "--upgrade"])
        self.assertEqual(codigo, 0)
        self.assertFalse(self.destino.exists())

    def test_comandos_docker(self):
        codigo, salida = ejecutar(deploy.main, [
            "--container", "moodle_web", "--owner", "www-data", "--upgrade", "--dry-run",
        ])
        self.assertEqual(codigo, 0)
        self.assertIn("docker exec moodle_web rm -rf /var/www/html/mod/assign/feedback/aiocr", salida)
        self.assertIn("docker exec moodle_web chown -R www-data", salida)
        self.assertIn("docker exec -u www-data moodle_web php /var/www/html/admin/cli/upgrade.php --non-interactive", salida)


class PruebasEmpaquetado(unittest.TestCase):

    def test_zip_con_aiocr_en_la_raiz(self):
        with tempfile.TemporaryDirectory() as tmp:
            zip_ruta = Path(tmp) / "plugin.zip"
            codigo, _ = ejecutar(package.main, ["--salida", str(zip_ruta)])
            self.assertEqual(codigo, 0)

            with zipfile.ZipFile(zip_ruta) as zf:
                nombres = zf.namelist()

        self.assertIn("aiocr/version.php", nombres)
        self.assertTrue(all(n.startswith("aiocr/") for n in nombres))
        self.assertFalse(any("\\" in n for n in nombres), "rutas con / aunque se genere en Windows")
        esperados = sorted("aiocr/" + p.relative_to(package.ORIGEN).as_posix()
                           for p in package.ORIGEN.rglob("*") if p.is_file())
        self.assertEqual(sorted(nombres), esperados)


if __name__ == "__main__":
    unittest.main()
