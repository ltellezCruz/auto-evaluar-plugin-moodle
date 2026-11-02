"""
moodle_client.py
Todo lo que habla con Moodle: llamadas a la API REST y descarga de los
archivos de una entrega usando el token.
"""

import os
from urllib.parse import urlparse

import requests
from fastapi import HTTPException

from .config import Config


def llamar_moodle(config: Config, wsfunction: str, params: dict | None = None):
    payload = {
        "wstoken": config.moodle_token,
        "wsfunction": wsfunction,
        "moodlewsrestformat": "json",
        **(params or {}),
    }
    respuesta = requests.get(
        f"{config.moodle_url}/webservice/rest/server.php",
        params=payload,
        timeout=config.moodle_timeout,
    )
    if respuesta.status_code != 200:
        raise HTTPException(status_code=502, detail="Moodle no respondió correctamente")
    data = respuesta.json()
    if isinstance(data, dict) and "exception" in data:
        raise HTTPException(status_code=400, detail=data.get("message"))
    return data


def validar_host_permitido(config: Config, fileurl: str) -> None:
    """Evita que el token de Moodle se envíe a un servidor ajeno si
    alguien llama al servicio con una fileurl de otro sitio."""
    host = (urlparse(fileurl).hostname or "").lower()
    if host not in config.allowed_file_hosts:
        raise ValueError(
            f"El host '{host}' no está en ALLOWED_FILE_HOSTS "
            f"({', '.join(sorted(config.allowed_file_hosts))}); no se envía el token."
        )


def descargar_archivo_moodle(config: Config, fileurl: str, nombre_archivo: str, carpeta: str) -> str:
    validar_host_permitido(config, fileurl)

    separador = "&" if "?" in fileurl else "?"
    url_con_token = f"{fileurl}{separador}token={config.moodle_token}"

    respuesta = requests.get(url_con_token, timeout=config.download_timeout)
    respuesta.raise_for_status()

    # basename: el nombre viene de la petición y no debe poder salir de la carpeta.
    ruta_destino = os.path.join(carpeta, os.path.basename(nombre_archivo) or "entrega")
    with open(ruta_destino, "wb") as f:
        f.write(respuesta.content)

    return ruta_destino
