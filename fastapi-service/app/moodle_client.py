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


def _cabecera_host(config: Config) -> dict:
    """Moodle rechaza (redirige) las peticiones cuyo Host no coincide con
    su wwwroot. Si FastAPI entra por una dirección interna, se presenta
    con el host público."""
    if not config.moodle_public_url:
        return {}
    return {"Host": urlparse(config.moodle_public_url).netloc}


def a_url_interna(config: Config, url: str) -> str:
    """Las URL de los archivos llegan con la dirección pública de Moodle
    (su wwwroot). Si MOODLE_PUBLIC_URL está definida, se cambian por
    MOODLE_URL para descargar por la red interna (contenedores, o el mismo
    equipo en vez de dar la vuelta por ngrok)."""
    publica = config.moodle_public_url
    if publica and (url == publica or url.startswith(publica + "/")):
        return config.moodle_url + url[len(publica):]
    return url


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
        headers=_cabecera_host(config),
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
    fileurl = a_url_interna(config, fileurl)
    validar_host_permitido(config, fileurl)

    separador = "&" if "?" in fileurl else "?"
    url_con_token = f"{fileurl}{separador}token={config.moodle_token}"

    respuesta = requests.get(
        url_con_token,
        headers=_cabecera_host(config),
        timeout=config.download_timeout,
        allow_redirects=False,
    )
    if respuesta.is_redirect:
        raise ValueError(
            f"Moodle redirigió la descarga a {respuesta.headers.get('Location')}. "
            "Revisa MOODLE_URL y MOODLE_PUBLIC_URL (deben coincidir con el wwwroot de Moodle)."
        )
    respuesta.raise_for_status()
    # Con token inválido, host incorrecto o sin permisos, Moodle responde
    # con un JSON de error (o una página HTML) en vez del archivo.
    tipo = respuesta.headers.get("Content-Type", "")
    if tipo.startswith("application/json"):
        try:
            mensaje = respuesta.json().get("error") or respuesta.text
        except ValueError:
            mensaje = respuesta.text
        raise ValueError(f"Moodle no entregó el archivo: {mensaje}")
    if tipo.startswith("text/html"):
        raise ValueError(
            "Moodle devolvió una página HTML en vez del archivo "
            "(token inválido o vencido, o falta el permiso del servicio web)."
        )

    # basename: el nombre viene de la petición y no debe poder salir de la carpeta.
    ruta_destino = os.path.join(carpeta, os.path.basename(nombre_archivo) or "entrega")
    with open(ruta_destino, "wb") as f:
        f.write(respuesta.content)

    return ruta_destino
