"""
config.py
Toda la configuración del servicio sale de aquí. Los valores se leen de
variables de entorno y, si no están, del archivo fastapi-service/.env.
Nada de claves, rutas ni direcciones escritas en el código.

Ver .env.example para la lista completa y su significado.
"""

import os
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse

CARPETA_SERVICIO = Path(__file__).resolve().parent.parent


def _leer_env(ruta: Path) -> dict:
    """Lector mínimo de archivos .env (CLAVE=valor, # comentarios)."""
    valores = {}
    if not ruta.is_file():
        return valores
    for linea in ruta.read_text(encoding="utf-8").splitlines():
        linea = linea.strip()
        if not linea or linea.startswith("#") or "=" not in linea:
            continue
        clave, valor = linea.split("=", 1)
        valor = valor.strip()
        if len(valor) >= 2 and valor[0] == valor[-1] and valor[0] in "\"'":
            valor = valor[1:-1]
        valores[clave.strip()] = valor
    return valores


def _ruta(valor: str) -> Path:
    """Las rutas relativas se resuelven desde fastapi-service/, no desde
    la carpeta donde se lanzó uvicorn."""
    ruta = Path(valor).expanduser()
    return ruta if ruta.is_absolute() else (CARPETA_SERVICIO / ruta).resolve()


@dataclass(frozen=True)
class Config:
    moodle_url: str
    moodle_token: str
    allowed_file_hosts: frozenset
    gemini_api_key: str
    gemini_model: str
    gemini_url: str
    output_dir: Path
    prompts_dir: Path
    pdf_dpi: int
    moodle_timeout: int
    download_timeout: int
    gemini_timeout: int


def cargar_config() -> Config:
    archivo_env = _leer_env(CARPETA_SERVICIO / ".env")

    def valor(clave, defecto=None, obligatorio=False):
        v = os.environ.get(clave) or archivo_env.get(clave) or defecto
        if obligatorio and not v:
            raise RuntimeError(
                f"Falta la variable {clave}. Defínela en fastapi-service/.env "
                "(copia .env.example) o como variable de entorno."
            )
        return v

    moodle_url = valor("MOODLE_URL", "http://127.0.0.1").rstrip("/")

    # Hosts a los que se les puede enviar el token al descargar archivos.
    # Si no se indican, solo el host de MOODLE_URL.
    hosts = valor("ALLOWED_FILE_HOSTS", "")
    allowed = {h.strip().lower() for h in hosts.split(",") if h.strip()}
    host_moodle = (urlparse(moodle_url).hostname or "").lower()
    allowed.add(host_moodle)
    # localhost y 127.0.0.1 son el mismo equipo: si Moodle se configuró con
    # uno, las URL de los archivos pueden llegar con el otro.
    if host_moodle in ("localhost", "127.0.0.1"):
        allowed.update({"localhost", "127.0.0.1"})

    gemini_model = valor("GEMINI_MODEL", "gemini-3.5-flash-lite")

    return Config(
        moodle_url=moodle_url,
        moodle_token=valor("MOODLE_TOKEN", obligatorio=True),
        allowed_file_hosts=frozenset(h for h in allowed if h),
        gemini_api_key=valor("GEMINI_API_KEY", obligatorio=True),
        gemini_model=gemini_model,
        gemini_url=(
            "https://generativelanguage.googleapis.com/v1beta/models/"
            f"{gemini_model}:generateContent"
        ),
        output_dir=_ruta(valor("OUTPUT_DIR", "../data/transcripciones")),
        prompts_dir=_ruta(valor("PROMPTS_DIR", "prompts")),
        pdf_dpi=int(valor("PDF_DPI", "200")),
        moodle_timeout=int(valor("MOODLE_TIMEOUT", "10")),
        download_timeout=int(valor("DOWNLOAD_TIMEOUT", "30")),
        gemini_timeout=int(valor("GEMINI_TIMEOUT", "120")),
    )


def leer_prompt(config: Config, nombre: str) -> str:
    # Se lee tal cual: el prompt de evaluación termina en una línea en
    # blanco a la que se le pega la transcripción.
    return (config.prompts_dir / nombre).read_text(encoding="utf-8")
