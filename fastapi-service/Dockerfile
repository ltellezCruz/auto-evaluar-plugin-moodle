# Imagen del servicio FastAPI. La configuración llega por variables de
# entorno (ver .env.example); el .env no se copia a la imagen.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app/ app/
COPY prompts/ prompts/

# Usuario sin privilegios.
RUN useradd --create-home aiocr && mkdir -p /data/transcripciones && chown aiocr /data/transcripciones
USER aiocr

ENV OUTPUT_DIR=/data/transcripciones

EXPOSE 8000
CMD ["python", "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
