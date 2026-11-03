"""
gemini_client.py
Llamadas a la API de Gemini (texto o imagen + texto).
"""

import base64
import logging
import mimetypes
from typing import Optional

import requests

from .config import Config

log = logging.getLogger("aiocr.gemini")


def obtener_consumo_tokens(respuesta_json):
    uso = respuesta_json.get("usageMetadata", {})
    return {
        "entrada": uso.get("promptTokenCount", 0),
        "salida": uso.get("candidatesTokenCount", 0),
        "razonamiento": uso.get("thoughtsTokenCount", 0),
        "total": uso.get("totalTokenCount", 0),
    }


def consultar_gemini(config: Config, pregunta: str, ruta_imagen: Optional[str] = None) -> tuple[str, dict]:
    """Devuelve (texto de la respuesta, consumo de tokens)."""
    partes = []

    if ruta_imagen:
        tipo_mime, _ = mimetypes.guess_type(ruta_imagen)
        tipo_mime = tipo_mime or "application/octet-stream"

        with open(ruta_imagen, "rb") as f:
            contenido_base64 = base64.b64encode(f.read()).decode("utf-8")

        partes.append({
            "inline_data": {
                "mime_type": tipo_mime,
                "data": contenido_base64,
            }
        })

    partes.append({"text": pregunta})

    datos = {"contents": [{"role": "user", "parts": partes}]}

    respuesta = requests.post(
        config.gemini_url,
        json=datos,
        headers={
            "Content-Type": "application/json",
            "x-goog-api-key": config.gemini_api_key,
        },
        timeout=config.gemini_timeout,
    )

    if respuesta.status_code != 200:
        raise RuntimeError(f"Gemini respondió con error {respuesta.status_code}: {respuesta.text}")

    resultado = respuesta.json()
    partes_respuesta = resultado["candidates"][0]["content"]["parts"]

    consumo = obtener_consumo_tokens(resultado)
    log.info(
        "Tokens Gemini (%s): entrada=%s salida=%s razonamiento=%s total=%s",
        config.gemini_model, consumo["entrada"], consumo["salida"],
        consumo["razonamiento"], consumo["total"],
    )

    return "".join(p.get("text", "") for p in partes_respuesta).strip(), consumo
