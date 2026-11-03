"""
transcripciones.py
Registro en disco de cada evaluación, para auditoría y para los análisis
de la tesis (tiempos, tokens, errores):

    <OUTPUT_DIR>/tarea_<assignmentid>/usuario_<userid>_<fecha>.txt   transcripción
    <OUTPUT_DIR>/tarea_<assignmentid>/usuario_<userid>_<fecha>.json  metadatos

Cada clic en "Auto Evaluar" crea archivos nuevos (no se sobrescribe nada).
Se usan los id numéricos de Moodle, no el nombre de usuario.

Para que la carpeta no crezca sin límite, los archivos con más de
TRANSCRIPTIONS_DAYS días se borran (como mucho una revisión por hora).
"""

import json
import logging
import threading
import time
from datetime import datetime
from pathlib import Path

from .config import Config

log = logging.getLogger("aiocr.transcripciones")

_INTERVALO_LIMPIEZA = 3600
_ultima_limpieza = 0.0
_candado = threading.Lock()


def guardar_evaluacion(config: Config, registro: dict, transcripcion: str) -> None:
    """Guarda la transcripción y los metadatos. Nunca lanza excepciones:
    el registro de auditoría no debe tumbar la respuesta al plugin."""
    if not config.save_transcriptions:
        return
    try:
        carpeta = config.output_dir / f"tarea_{int(registro['assignmentid'])}"
        carpeta.mkdir(parents=True, exist_ok=True)
        base = f"usuario_{int(registro['userid'])}_{datetime.now():%Y-%m-%d_%H%M%S}"
        # Dos clics en el mismo segundo no se pisan.
        n = 1
        nombre = base
        while (carpeta / f"{nombre}.json").exists():
            n += 1
            nombre = f"{base}_{n}"

        if transcripcion:
            (carpeta / f"{nombre}.txt").write_text(transcripcion, encoding="utf-8")
            registro = {**registro, "transcripcion": f"{nombre}.txt"}
        (carpeta / f"{nombre}.json").write_text(
            json.dumps(registro, ensure_ascii=False, indent=2), encoding="utf-8"
        )
    except Exception:
        log.exception("No se pudo guardar el registro de la evaluación")

    limpiar_antiguas(config)


def limpiar_antiguas(config: Config, forzar: bool = False) -> int:
    """Borra transcripciones y metadatos con más de TRANSCRIPTIONS_DAYS días.
    Devuelve cuántos archivos borró."""
    global _ultima_limpieza
    if config.transcriptions_days <= 0 or not config.output_dir.is_dir():
        return 0
    with _candado:
        ahora = time.time()
        if not forzar and ahora - _ultima_limpieza < _INTERVALO_LIMPIEZA:
            return 0
        _ultima_limpieza = ahora

    limite = ahora - config.transcriptions_days * 86400
    borrados = 0
    for archivo in config.output_dir.rglob("*"):
        try:
            if archivo.is_file() and archivo.suffix in (".txt", ".json") and archivo.stat().st_mtime < limite:
                archivo.unlink()
                borrados += 1
        except OSError:
            log.warning("No se pudo borrar %s", archivo)
    # Carpetas de tareas que quedaron vacías.
    for carpeta in sorted(config.output_dir.glob("tarea_*"), reverse=True):
        try:
            if carpeta.is_dir() and not any(carpeta.iterdir()):
                carpeta.rmdir()
        except OSError:
            pass
    if borrados:
        log.info("Borradas %s transcripciones con más de %s días", borrados, config.transcriptions_days)
    return borrados
