"""
package_plugin.py
Genera el zip instalable del plugin a partir de moodle-plugin/aiocr:

    python scripts/package_plugin.py
    -> dist/assignfeedback_aiocr_<release>_<version>.zip

El zip tiene la carpeta aiocr/ en la raíz, que es el formato que acepta
Administración del sitio → Extensiones → Instalar complementos, y el que
se descomprime a mano en <moodle>/mod/assign/feedback/.

Opciones:
    --salida RUTA   ruta del zip (por defecto en dist/)

Solo usa la biblioteca estándar de Python (3.8+).
"""

import argparse
import re
import sys
import zipfile
from pathlib import Path

RAIZ_REPO = Path(__file__).resolve().parent.parent
ORIGEN = RAIZ_REPO / "moodle-plugin" / "aiocr"
COMPONENTE = "assignfeedback_aiocr"

IGNORAR_NOMBRES = {"__pycache__", ".DS_Store", "Thumbs.db", "desktop.ini"}
IGNORAR_SUFIJOS = (".pyc", "~", ".swp", ".orig", ".bak")


def leer_version(version_php: Path) -> tuple[str, str]:
    texto = version_php.read_text(encoding="utf-8")
    if f"'{COMPONENTE}'" not in texto:
        raise SystemExit(f"ERROR: {version_php} no declara el componente {COMPONENTE}.")
    version = re.search(r"\$plugin->version\s*=\s*(\d+)", texto)
    release = re.search(r"\$plugin->release\s*=\s*'([^']+)'", texto)
    if not version:
        raise SystemExit(f"ERROR: no se encontró $plugin->version en {version_php}.")
    return version.group(1), (release.group(1) if release else "dev")


def archivos_del_plugin():
    for ruta in sorted(ORIGEN.rglob("*")):
        relativa = ruta.relative_to(ORIGEN)
        if any(parte in IGNORAR_NOMBRES or parte.startswith("{") for parte in relativa.parts):
            continue
        if ruta.is_file() and not ruta.name.endswith(IGNORAR_SUFIJOS):
            yield ruta, relativa


def main(argv=None):
    for flujo in (sys.stdout, sys.stderr):
        if hasattr(flujo, "reconfigure"):
            flujo.reconfigure(encoding="utf-8", errors="replace")

    parser = argparse.ArgumentParser(description="Empaqueta el plugin aiocr como zip instalable.")
    parser.add_argument("--salida", help="Ruta del zip a generar.")
    args = parser.parse_args(argv)

    version, release = leer_version(ORIGEN / "version.php")
    salida = Path(args.salida) if args.salida else RAIZ_REPO / "dist" / f"{COMPONENTE}_{release}_{version}.zip"
    salida.parent.mkdir(parents=True, exist_ok=True)

    total = 0
    with zipfile.ZipFile(salida, "w", zipfile.ZIP_DEFLATED) as zf:
        for ruta, relativa in archivos_del_plugin():
            # Rutas con "/" aunque se genere en Windows: Moodle las espera así.
            zf.write(ruta, "aiocr/" + relativa.as_posix())
            total += 1

    print(f"{salida} ({total} archivos, versión {version}, release {release})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
