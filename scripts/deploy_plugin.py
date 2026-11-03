"""
deploy_plugin.py
Copia el plugin desde el repositorio (moodle-plugin/aiocr) a una
instalación de Moodle y, si se pide, ejecuta la actualización de Moodle.

El código se edita SOLO en el repositorio. La carpeta
<moodle>/mod/assign/feedback/aiocr es un destino: este script la borra
y la vuelve a crear en cada despliegue (antes guarda una copia de
respaldo en dist/backups/).

Destinos soportados:
    1. Carpeta local (Windows, Linux o volumen montado de Docker):
           python scripts/deploy_plugin.py --moodle-dir "C:\\Moodle45\\server\\moodle"
    2. Contenedor Docker con Moodle dentro de la imagen:
           python scripts/deploy_plugin.py --container moodle_web
    3. Volumen montado + comandos dentro del contenedor (moodle-docker):
           python scripts/deploy_plugin.py --moodle-dir ./moodle --container moodle-webserver-1 --upgrade

Opciones útiles:
    --upgrade       ejecuta admin/cli/upgrade.php --non-interactive
    --purge-caches  ejecuta admin/cli/purge_caches.php
    --php RUTA      intérprete PHP para los comandos locales (por defecto "php")
    --owner USUARIO cambia el dueño de los archivos (Linux/Docker, ej. www-data)
    --dry-run       muestra lo que haría sin cambiar nada
    --permitir-retroceso  copia aunque sea una versión anterior (ver docs/actualizacion.md)

--moodle-dir también se puede dar con la variable de entorno MOODLE_DIR.
Solo usa la biblioteca estándar de Python (3.8+).
"""

import argparse
import os
import re
import shutil
import subprocess
import sys
from datetime import datetime
from pathlib import Path

COMPONENTE = "assignfeedback_aiocr"
RUTA_EN_MOODLE = ("mod", "assign", "feedback", "aiocr")

RAIZ_REPO = Path(__file__).resolve().parent.parent
ORIGEN = RAIZ_REPO / "moodle-plugin" / "aiocr"
CARPETA_RESPALDOS = RAIZ_REPO / "dist" / "backups"

IGNORAR = shutil.ignore_patterns(
    "__pycache__", "*.pyc", ".DS_Store", "Thumbs.db", "desktop.ini", "*~", "*.swp"
)


class ErrorDespliegue(Exception):
    pass


def ejecutar(comando, dry_run):
    print("  $ " + subprocess.list2cmdline(comando))
    if dry_run:
        return
    resultado = subprocess.run(comando)
    if resultado.returncode != 0:
        raise ErrorDespliegue(f"El comando terminó con código {resultado.returncode}.")


def es_plugin_aiocr(carpeta: Path) -> bool:
    version = carpeta / "version.php"
    if not version.is_file():
        return False
    return COMPONENTE in version.read_text(encoding="utf-8", errors="ignore")


def leer_version(texto: str):
    """$plugin->version de un version.php (None si no se encuentra)."""
    encontrada = re.search(r"\$plugin->version\s*=\s*(\d+)", texto)
    return int(encontrada.group(1)) if encontrada else None


def version_origen() -> int:
    return leer_version((ORIGEN / "version.php").read_text(encoding="utf-8"))


def impedir_retroceso(instalada, permitir: bool):
    """Moodle no admite volver a una versión anterior del plugin: si el
    código es más viejo que lo registrado en la base de datos, bloquea TODO
    el sitio con "Cannot downgrade". Por eso no se copia código más viejo
    que el que ya está instalado."""
    nueva = version_origen()
    if instalada is None or nueva >= instalada:
        if instalada is not None:
            print(f"- Versión instalada {instalada} -> nueva {nueva}")
        return
    mensaje = (
        f"El repositorio tiene la versión {nueva}, más antigua que la instalada ({instalada}). "
        "Moodle no permite volver atrás: el sitio quedaría bloqueado con \"Cannot downgrade\". "
        "Para regresar a una versión anterior hay que restaurar también la base de datos "
        "(ver docs/actualizacion.md)."
    )
    if not permitir:
        raise ErrorDespliegue(mensaje)
    print("ADVERTENCIA: " + mensaje)


def validar_origen():
    if not es_plugin_aiocr(ORIGEN):
        raise ErrorDespliegue(f"No se encontró el plugin en {ORIGEN} (falta version.php).")


def validar_moodle_local(moodle_dir: Path) -> Path:
    if not (moodle_dir / "version.php").is_file() or not (moodle_dir / "lib" / "moodlelib.php").is_file():
        raise ErrorDespliegue(
            f"{moodle_dir} no parece la carpeta de código de Moodle "
            "(debe contener version.php y lib/moodlelib.php). "
            "En el paquete de Windows es <CARPETA_MOODLE>\\server\\moodle."
        )
    padre = moodle_dir.joinpath(*RUTA_EN_MOODLE[:-1])
    if not padre.is_dir():
        raise ErrorDespliegue(f"No existe {padre}: ¿está instalado mod_assign?")
    return moodle_dir.joinpath(*RUTA_EN_MOODLE)


def respaldar(destino: Path, dry_run: bool):
    marca = datetime.now().strftime("%Y%m%d_%H%M%S")
    respaldo = CARPETA_RESPALDOS / f"aiocr_{marca}"
    print(f"- Respaldo de la versión instalada en {respaldo}")
    if not dry_run:
        respaldo.parent.mkdir(parents=True, exist_ok=True)
        shutil.copytree(destino, respaldo)


def desplegar_local(moodle_dir: Path, owner, dry_run, permitir_retroceso=False):
    destino = validar_moodle_local(moodle_dir)

    # is_junction existe desde Python 3.12 (enlaces "mklink /J" de Windows).
    if destino.is_symlink() or getattr(destino, "is_junction", lambda: False)():
        raise ErrorDespliegue(
            f"{destino} es un enlace. Bórralo a mano (solo el enlace) antes de desplegar por copia."
        )

    if destino.exists():
        if not es_plugin_aiocr(destino):
            raise ErrorDespliegue(
                f"{destino} existe pero no contiene {COMPONENTE}; no se borrará por seguridad."
            )
        impedir_retroceso(leer_version((destino / "version.php").read_text(encoding="utf-8")), permitir_retroceso)
        respaldar(destino, dry_run)
        print(f"- Borrando {destino}")
        if not dry_run:
            shutil.rmtree(destino)

    print(f"- Copiando {ORIGEN} -> {destino}")
    if not dry_run:
        shutil.copytree(ORIGEN, destino, ignore=IGNORAR)

    if owner:
        if os.name == "nt":
            print("- --owner se ignora en Windows.")
        else:
            ejecutar(["chown", "-R", owner, str(destino)], dry_run)


def desplegar_contenedor(contenedor, ruta_moodle, owner, dry_run, permitir_retroceso=False):
    destino = "/".join([ruta_moodle.rstrip("/"), *RUTA_EN_MOODLE])
    padre = destino.rsplit("/", 1)[0]

    print(f"- Verificando {padre} dentro de {contenedor}")
    ejecutar(["docker", "exec", contenedor, "test", "-d", padre], dry_run)

    if not dry_run:
        actual = subprocess.run(["docker", "exec", contenedor, "cat", f"{destino}/version.php"],
                                capture_output=True, text=True, encoding="utf-8", errors="replace")
        if actual.returncode == 0:
            impedir_retroceso(leer_version(actual.stdout), permitir_retroceso)

    # docker cp copia DENTRO del destino si ya existe; por eso se borra antes.
    print(f"- Reemplazando {destino} en {contenedor}")
    ejecutar(["docker", "exec", contenedor, "rm", "-rf", destino], dry_run)
    ejecutar(["docker", "cp", str(ORIGEN), f"{contenedor}:{destino}"], dry_run)

    if owner:
        ejecutar(["docker", "exec", contenedor, "chown", "-R", owner, destino], dry_run)


def comando_cli(script, args, php, contenedor, ruta_contenedor, moodle_dir, owner=None):
    # Los comandos de Moodle se ejecutan como el dueño de los archivos (el
    # usuario del servidor web): si corren como root, dejan cachés en
    # moodledata que Apache después no puede modificar.
    usuario = owner.split(":")[0] if owner else None
    if contenedor:
        como = ["-u", usuario] if usuario else []
        return ["docker", "exec", *como, contenedor, "php", f"{ruta_contenedor.rstrip('/')}/admin/cli/{script}", *args]
    comando = [php, str(moodle_dir / "admin" / "cli" / script), *args]
    if usuario and os.name != "nt":
        return ["sudo", "-u", usuario, *comando]
    return comando


def main(argv=None):
    # La consola de Windows usa cp1252 por defecto y falla con tildes o flechas.
    for flujo in (sys.stdout, sys.stderr):
        if hasattr(flujo, "reconfigure"):
            # line_buffering: que los mensajes salgan en orden con los de PHP.
            flujo.reconfigure(encoding="utf-8", errors="replace", line_buffering=True)

    parser = argparse.ArgumentParser(description="Despliega el plugin aiocr en Moodle.")
    parser.add_argument("--moodle-dir", default=os.environ.get("MOODLE_DIR"),
                        help="Carpeta de código de Moodle en este equipo (o variable MOODLE_DIR).")
    parser.add_argument("--container", help="Nombre del contenedor Docker de Moodle.")
    parser.add_argument("--container-path", default="/var/www/html",
                        help="Carpeta de Moodle dentro del contenedor (por defecto /var/www/html).")
    parser.add_argument("--php", default="php", help="Intérprete PHP para comandos locales.")
    parser.add_argument("--owner", help="Dueño de los archivos copiados (ej. www-data).")
    parser.add_argument("--upgrade", action="store_true", help="Ejecuta admin/cli/upgrade.php.")
    parser.add_argument("--purge-caches", action="store_true", help="Ejecuta admin/cli/purge_caches.php.")
    parser.add_argument("--dry-run", action="store_true", help="Muestra los pasos sin ejecutarlos.")
    parser.add_argument("--permitir-retroceso", action="store_true",
                        help="Copia aunque el código sea más viejo que el instalado (solo si ya restauraste "
                             "la base de datos a esa versión).")
    args = parser.parse_args(argv)

    if not args.moodle_dir and not args.container:
        parser.error("indica --moodle-dir (o MOODLE_DIR) o --container.")

    moodle_dir = Path(args.moodle_dir).expanduser().resolve() if args.moodle_dir else None

    # Una ruta relativa como "server/php/php.exe" falla en Windows al
    # lanzar el proceso; se convierte a absoluta. "php" (en el PATH) se deja.
    if Path(args.php).expanduser().exists():
        args.php = str(Path(args.php).expanduser().resolve())

    try:
        validar_origen()
        if args.dry_run:
            print("(dry-run: no se cambiará nada)")

        if moodle_dir:
            # Con --container además, se asume volumen montado: se copia en
            # disco y los comandos de Moodle se ejecutan en el contenedor.
            desplegar_local(moodle_dir, None if args.container else args.owner, args.dry_run,
                            args.permitir_retroceso)
            if args.container and args.owner:
                destino = "/".join([args.container_path.rstrip("/"), *RUTA_EN_MOODLE])
                ejecutar(["docker", "exec", args.container, "chown", "-R", args.owner, destino], args.dry_run)
        else:
            desplegar_contenedor(args.container, args.container_path, args.owner, args.dry_run,
                                 args.permitir_retroceso)

        if args.upgrade:
            print("- Actualizando Moodle")
            ejecutar(comando_cli("upgrade.php", ["--non-interactive"], args.php,
                                 args.container, args.container_path, moodle_dir, args.owner), args.dry_run)
        if args.purge_caches:
            print("- Purgando cachés")
            ejecutar(comando_cli("purge_caches.php", [], args.php,
                                 args.container, args.container_path, moodle_dir, args.owner), args.dry_run)

    except (ErrorDespliegue, OSError) as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return 1

    print("Listo." if not args.dry_run else "Fin del dry-run.")
    if not args.upgrade:
        print("Recuerda: si cambiaste db/ o version.php, entra a Administración del sitio → Notificaciones "
              "(o usa --upgrade). Si cambiaste plantillas o textos, purga cachés.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
