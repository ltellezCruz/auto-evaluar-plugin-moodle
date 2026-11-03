"""
Pruebas de scripts/deploy_plugin.py y scripts/package_plugin.py contra un
Moodle simulado en una carpeta temporal.

Desde la raíz del repositorio:
    python -m unittest discover -s tests -v
"""

import importlib.util
import io
import re
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

    def poner_version_instalada(self, version):
        ejecutar(deploy.main, ["--moodle-dir", str(self.moodle)])
        archivo = self.destino / "version.php"
        texto = archivo.read_text(encoding="utf-8")
        archivo.write_text(re.sub(r"\$plugin->version\s*=\s*\d+", f"$plugin->version = {version}", texto),
                           encoding="utf-8")

    def test_no_instala_una_version_anterior(self):
        self.poner_version_instalada(deploy.version_origen() + 1)
        codigo, salida = ejecutar(deploy.main, ["--moodle-dir", str(self.moodle)])
        self.assertEqual(codigo, 1)
        self.assertIn("Cannot downgrade", salida)
        # No tocó lo instalado ni hizo respaldo.
        self.assertEqual(deploy.leer_version((self.destino / "version.php").read_text(encoding="utf-8")),
                         deploy.version_origen() + 1)
        self.assertFalse((self.tmp / "respaldos").exists())

    def test_permitir_retroceso_explicito(self):
        self.poner_version_instalada(deploy.version_origen() + 1)
        codigo, salida = ejecutar(deploy.main, ["--moodle-dir", str(self.moodle), "--permitir-retroceso"])
        self.assertEqual(codigo, 0)
        self.assertIn("ADVERTENCIA", salida)

    def test_actualizacion_muestra_versiones(self):
        self.poner_version_instalada(deploy.version_origen() - 1)
        codigo, salida = ejecutar(deploy.main, ["--moodle-dir", str(self.moodle)])
        self.assertEqual(codigo, 0)
        self.assertIn(f"-> nueva {deploy.version_origen()}", salida)

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


class PruebasVersiones(unittest.TestCase):

    def test_plugin_y_servicio_con_la_misma_version(self):
        """El release del plugin y la versión de FastAPI van a la par (CHANGELOG.md)."""
        texto = (deploy.ORIGEN / "version.php").read_text(encoding="utf-8")
        release = re.search(r"\$plugin->release\s*=\s*'([^']+)'", texto).group(1)
        init = (RAIZ_REPO / "fastapi-service" / "app" / "__init__.py").read_text(encoding="utf-8")
        servicio = re.search(r'__version__\s*=\s*"([^"]+)"', init).group(1)
        self.assertEqual(release, servicio)

    def test_changelog_menciona_la_version(self):
        texto = (deploy.ORIGEN / "version.php").read_text(encoding="utf-8")
        release = re.search(r"\$plugin->release\s*=\s*'([^']+)'", texto).group(1)
        self.assertIn(f"## {release}", (RAIZ_REPO / "CHANGELOG.md").read_text(encoding="utf-8"))

    def test_upgrade_php_llega_a_la_version_actual(self):
        """Si se sube $plugin->version con un paso de actualización, el último
        savepoint de db/upgrade.php no puede ser mayor que la versión."""
        version = deploy.version_origen()
        upgrade = (deploy.ORIGEN / "db" / "upgrade.php").read_text(encoding="utf-8")
        puntos = [int(v) for v in re.findall(r"upgrade_plugin_savepoint\(true,\s*(\d+)", upgrade)]
        self.assertTrue(puntos)
        self.assertLessEqual(max(puntos), version)
        self.assertEqual(puntos, sorted(puntos), "los pasos van en orden")


class PruebasPlugin(unittest.TestCase):
    """Revisiones estáticas del código del plugin."""

    def claves(self, idioma):
        texto = (deploy.ORIGEN / "lang" / idioma / "assignfeedback_aiocr.php").read_text(encoding="utf-8")
        return set(re.findall(r"\$string\['([^']+)'\]", texto))

    def test_idiomas_con_las_mismas_claves(self):
        self.assertEqual(self.claves("en"), self.claves("es"))

    def test_textos_usados_existen(self):
        usados = set()
        for archivo in deploy.ORIGEN.rglob("*"):
            if archivo.suffix in (".php", ".mustache"):
                texto = archivo.read_text(encoding="utf-8")
                usados |= set(re.findall(r"get_string\(\s*'([^'$]+)'\s*,\s*'assignfeedback_aiocr'", texto))
                usados |= set(re.findall(r"\{\{#str\}\}\s*([\w:]+)\s*,\s*assignfeedback_aiocr", texto))
        fabrica = (deploy.ORIGEN / "classes/local/provider_factory.php").read_text(encoding="utf-8")
        sin_comentarios = "\n".join(l for l in fabrica.splitlines() if not l.strip().startswith("//"))
        proveedores = re.findall(r"'(\w+_adapter)'\s*=>", sin_comentarios)
        usados |= {f"provider:{p}" for p in proveedores}
        self.assertTrue(usados)
        self.assertEqual(usados - self.claves("en"), set())

    def test_el_javascript_no_inserta_html(self):
        """Lo que llega del servidor (errores, IA) se inserta como texto."""
        self.assertNotIn("innerHTML", (deploy.ORIGEN / "locallib.php").read_text(encoding="utf-8"))

    def test_la_plantilla_no_inserta_html_sin_escapar(self):
        self.assertNotIn("{{{", (deploy.ORIGEN / "templates" / "feedback_button.mustache").read_text(encoding="utf-8"))


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
