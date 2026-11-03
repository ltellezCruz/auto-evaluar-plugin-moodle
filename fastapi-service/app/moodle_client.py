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

# Tipos de archivo que se procesan, con la extensión con que se guardan.
TIPOS_PERMITIDOS = {
    "application/pdf": ".pdf",
    "image/png": ".png",
    "image/jpeg": ".jpg",
}
# Única ruta de Moodle a la que se le envía el token: archivos por servicio web.
RUTA_ARCHIVOS = "/webservice/pluginfile.php/"


class TipoNoAdmitido(ValueError):
    """El archivo existe y se pudo pedir, pero no es PDF ni imagen: en una
    entrega con varios archivos se omite en vez de cancelar todo."""


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
    partes = urlparse(fileurl)
    host = (partes.hostname or "").lower()
    if partes.scheme not in ("http", "https") or host not in config.allowed_file_hosts:
        raise ValueError(
            f"El host '{host}' no está en ALLOWED_FILE_HOSTS "
            f"({', '.join(sorted(config.allowed_file_hosts))}); no se envía el token."
        )
    # Ni siquiera dentro de Moodle se envía el token a otra ruta que no sea
    # la de archivos (evita usarlo contra otras páginas o funciones).
    if RUTA_ARCHIVOS not in partes.path or ".." in partes.path:
        raise ValueError(f"La URL no es un archivo de Moodle ({RUTA_ARCHIVOS}); no se envía el token.")


def descargar_archivo_moodle(config: Config, fileurl: str, carpeta: str, nombre: str = "entrega") -> str:
    """Descarga el archivo a la carpeta y devuelve su ruta. El nombre en
    disco lo decide el tipo de archivo, no la petición."""
    fileurl = a_url_interna(config, fileurl)
    validar_host_permitido(config, fileurl)

    separador = "&" if "?" in fileurl else "?"
    url_con_token = f"{fileurl}{separador}token={config.moodle_token}"

    with requests.get(
        url_con_token,
        headers=_cabecera_host(config),
        timeout=config.download_timeout,
        allow_redirects=False,
        stream=True,  # se lee por partes para poder cortar si es muy grande
    ) as respuesta:
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

        tipo_base = tipo.split(";")[0].strip().lower()
        if tipo_base not in TIPOS_PERMITIDOS:
            raise TipoNoAdmitido(f"Tipo de archivo no admitido: {tipo_base or 'desconocido'} (solo PDF, PNG o JPG).")

        # El archivo nunca se carga completo en memoria y se corta al pasar el límite.
        limite = config.max_file_mb * 1024 * 1024
        declarado = int(respuesta.headers.get("Content-Length") or 0)
        if declarado > limite:
            raise ValueError(f"El archivo pesa {declarado / 1024**2:.1f} MB; el máximo es {config.max_file_mb} MB.")

        ruta_destino = os.path.join(carpeta, nombre + TIPOS_PERMITIDOS[tipo_base])
        recibidos = 0
        with open(ruta_destino, "wb") as f:
            for bloque in respuesta.iter_content(chunk_size=64 * 1024):
                recibidos += len(bloque)
                if recibidos > limite:
                    raise ValueError(f"El archivo supera el máximo de {config.max_file_mb} MB.")
                f.write(bloque)

    return ruta_destino
