#!/bin/bash
# Prueba de punta a punta en el entorno Docker (con los contenedores encendidos):
#   1. crea un curso, un estudiante y una entrega con entrega_prueba.pdf
#   2. ejecuta lo mismo que el clic en "Auto Evaluar"
#   3. muestra si el comentario quedó guardado
#
# Hace llamadas reales a Gemini (1 página: 2 llamadas).
# Uso (desde la raíz del repositorio):  bash docker/pruebas/prueba_e2e.sh
set -euo pipefail
export MSYS_NO_PATHCONV=1   # Git Bash en Windows: no convertir rutas /tmp/...

# pwd -W: ruta de Windows en Git Bash (docker cp no entiende /c/...).
CARPETA="$(cd "$(dirname "$0")" && (pwd -W 2>/dev/null || pwd))"
CONTENEDOR="${CONTENEDOR:-auto-evaluar-moodle-1}"

for archivo in entrega_prueba.pdf datos_prueba.php boton.php; do
    docker cp "$CARPETA/$archivo" "$CONTENEDOR:/tmp/$archivo"
done
docker exec "$CONTENEDOR" chmod 644 /tmp/entrega_prueba.pdf /tmp/datos_prueba.php /tmp/boton.php

echo "== Creando datos de prueba"
read -r ASSIGNMENTID USERID < <(docker exec "$CONTENEDOR" runuser -u www-data -- php /tmp/datos_prueba.php | tail -1)
echo "tarea $ASSIGNMENTID, estudiante $USERID"

echo "== Ejecutando Auto Evaluar"
docker exec "$CONTENEDOR" runuser -u www-data -- php /tmp/boton.php "$ASSIGNMENTID" "$USERID"
