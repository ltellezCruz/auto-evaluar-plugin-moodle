"""
monitor.py
Mide cada INTERVALO segundos el uso de CPU, RAM, disco y red del equipo y
de los procesos de Moodle (web y base de datos) y de FastAPI, y lo guarda
en un CSV. Junto al CSV escribe un .json con los datos del experimento,
para poder reproducir y comparar mediciones.

Uso (desde la raíz del repositorio):
    python tools/monitor/monitor.py --etiqueta E1_gemini_flash_lite --notas "10 PDF de 2 páginas"
    -> data/mediciones/2026-11-03_101500_E1_gemini_flash_lite.csv
       data/mediciones/2026-11-03_101500_E1_gemini_flash_lite.json

Ctrl+C termina la medición. Nunca sobrescribe un archivo existente.

Opciones:
    --etiqueta TEXTO   nombre corto del experimento (va en el nombre del archivo)
    --notas TEXTO      descripción libre (modelo, cantidad de PDF, páginas...)
    --intervalo SEG    segundos entre mediciones (por defecto 1.0)
    --salida RUTA      ruta del CSV (por defecto data/mediciones/<fecha>_<etiqueta>.csv)

Identifica los procesos por nombre en Windows (paquete de Moodle/XAMPP) y
en Linux (Apache/PHP-FPM, MariaDB/MySQL). Con Docker Desktop, los procesos
de los contenedores corren dentro de una máquina virtual y no son visibles
desde Windows o macOS: las columnas por proceso quedarán en 0 y solo valen
las del equipo completo (cpu_total, ram_total, disco y red).

Requiere psutil (ver requirements.txt de esta carpeta).
"""

import argparse
import csv
import json
import platform
import re
import sys
import time
from datetime import datetime
from pathlib import Path

import psutil

RAIZ_REPO = Path(__file__).resolve().parents[2]
CARPETA_MEDICIONES = RAIZ_REPO / "data" / "mediciones"

N_CPU = psutil.cpu_count(logical=True) or 1

# Nombres de proceso por grupo (en minúsculas), Windows y Linux.
PROCESOS_WEB = {
    "httpd.exe", "apache.exe", "php.exe", "php-cgi.exe",
    "httpd", "apache2", "php-fpm", "php", "nginx",
}
PROCESOS_BD = {"mysqld.exe", "mariadbd.exe", "mysqld", "mariadbd"}
PROCESOS_PYTHON = {"python.exe", "pythonw.exe", "python", "python3"}
# Solo se cuentan los procesos de Python que ejecutan el servicio.
MARCAS_FASTAPI = ("uvicorn", "app.main", "restmain")

COLUMNAS = [
    "fecha_hora",
    "cpu_total_pct",
    "ram_total_pct",
    "ram_disponible_mb",
    "moodle_web_cpu_pct",
    "moodle_web_ram_mb",
    "moodle_bd_cpu_pct",
    "moodle_bd_ram_mb",
    "python_cpu_pct",
    "python_ram_mb",
    "python_procesos",
    "disco_lectura_mb_s",
    "disco_escritura_mb_s",
    "red_envio_mb_s",
    "red_recepcion_mb_s",
]


def clasificar_proceso(proc):
    try:
        nombre = proc.name().lower()
        # php-fpm aparece como "php-fpm8.2" en algunas distribuciones.
        nombre_base = re.sub(r"[\d.]+$", "", nombre)

        if nombre in PROCESOS_WEB or nombre_base in PROCESOS_WEB:
            return "Moodle_Web"

        if nombre in PROCESOS_BD:
            return "Moodle_BD"

        if nombre in PROCESOS_PYTHON or nombre_base in PROCESOS_PYTHON:
            # Se inspecciona el comando de inicio SOLO para identificar
            # el servicio. Nunca se guarda el comando en el CSV.
            argumentos = " ".join(proc.cmdline()).lower()
            if any(marca in argumentos for marca in MARCAS_FASTAPI):
                return "Python_FastAPI"

    except (psutil.NoSuchProcess, psutil.AccessDenied, psutil.ZombieProcess):
        pass

    return None


def obtener_procesos():
    grupos = {"Moodle_Web": [], "Moodle_BD": [], "Python_FastAPI": []}
    for proc in psutil.process_iter():
        grupo = clasificar_proceso(proc)
        if grupo:
            grupos[grupo].append(proc)
    return grupos


def ruta_sin_sobrescribir(ruta: Path) -> Path:
    if not ruta.exists():
        return ruta
    n = 2
    while True:
        candidata = ruta.with_name(f"{ruta.stem}_{n}{ruta.suffix}")
        if not candidata.exists():
            return candidata
        n += 1


def nombre_seguro(texto: str) -> str:
    return re.sub(r"[^\w.-]+", "_", texto.strip()).strip("_") or "medicion"


def escribir_metadatos(ruta: Path, datos: dict):
    ruta.write_text(json.dumps(datos, ensure_ascii=False, indent=2), encoding="utf-8")


def main(argv=None):
    for flujo in (sys.stdout, sys.stderr):
        if hasattr(flujo, "reconfigure"):
            flujo.reconfigure(encoding="utf-8", errors="replace")

    parser = argparse.ArgumentParser(description="Monitor de recursos de Auto Evaluar.")
    parser.add_argument("--etiqueta", default="medicion", help="Nombre corto del experimento.")
    parser.add_argument("--notas", default="", help="Descripción libre del experimento.")
    parser.add_argument("--intervalo", type=float, default=1.0, help="Segundos entre mediciones.")
    parser.add_argument("--salida", help="Ruta del CSV.")
    args = parser.parse_args(argv)

    inicio = datetime.now()
    if args.salida:
        archivo = Path(args.salida).expanduser().resolve()
    else:
        nombre = f"{inicio:%Y-%m-%d_%H%M%S}_{nombre_seguro(args.etiqueta)}.csv"
        archivo = CARPETA_MEDICIONES / nombre
    archivo = ruta_sin_sobrescribir(archivo)
    archivo.parent.mkdir(parents=True, exist_ok=True)
    archivo_meta = archivo.with_suffix(".json")

    memoria_total = psutil.virtual_memory().total
    metadatos = {
        "etiqueta": args.etiqueta,
        "notas": args.notas,
        "inicio": inicio.isoformat(timespec="seconds"),
        "fin": None,
        "intervalo_s": args.intervalo,
        "filas": 0,
        "csv": archivo.name,
        "equipo": {
            "sistema": f"{platform.system()} {platform.release()}",
            "procesador": platform.processor() or platform.machine(),
            "cpu_logicos": N_CPU,
            "ram_total_mb": round(memoria_total / 1024**2),
            "python": platform.python_version(),
        },
    }
    escribir_metadatos(archivo_meta, metadatos)

    print("Monitor Auto Evaluar")
    print("Intervalo:", args.intervalo, "segundos")
    print("Archivo:", archivo)
    print("Procesadores lógicos:", N_CPU)
    print("Presiona Ctrl+C para finalizar.\n")

    # CPU acumulada por PID, para calcular el porcentaje a partir de sus diferencias.
    anteriores = {}
    tiempo_anterior = time.monotonic()

    disco_anterior = psutil.disk_io_counters()
    red_anterior = psutil.net_io_counters()
    filas = 0

    with archivo.open("w", newline="", encoding="utf-8-sig") as f:
        escritor = csv.DictWriter(f, fieldnames=COLUMNAS)
        escritor.writeheader()

        try:
            while True:
                time.sleep(args.intervalo)

                ahora = time.monotonic()
                dt = max(ahora - tiempo_anterior, 0.001)

                grupos = obtener_procesos()
                actuales = {}
                medidas = {}

                for grupo, procesos in grupos.items():
                    cpu_suma = 0.0
                    ram_suma = 0.0
                    for proc in procesos:
                        try:
                            identificador = (proc.pid, proc.create_time())
                            tiempos = proc.cpu_times()
                            cpu_actual = tiempos.user + tiempos.system
                            if identificador in anteriores:
                                diferencia = max(0, cpu_actual - anteriores[identificador])
                                cpu_suma += 100 * diferencia / (dt * N_CPU)
                            actuales[identificador] = cpu_actual
                            ram_suma += proc.memory_info().rss / 1024**2
                        except (psutil.NoSuchProcess, psutil.AccessDenied):
                            continue
                    medidas[grupo] = (round(cpu_suma, 2), round(ram_suma, 2))

                anteriores = actuales
                tiempo_anterior = ahora

                disco = psutil.disk_io_counters()
                red = psutil.net_io_counters()

                lectura = escritura = envio = recepcion = 0.0
                if disco and disco_anterior:
                    lectura = max(0, disco.read_bytes - disco_anterior.read_bytes) / dt / 1024**2
                    escritura = max(0, disco.write_bytes - disco_anterior.write_bytes) / dt / 1024**2
                if red and red_anterior:
                    envio = max(0, red.bytes_sent - red_anterior.bytes_sent) / dt / 1024**2
                    recepcion = max(0, red.bytes_recv - red_anterior.bytes_recv) / dt / 1024**2

                disco_anterior = disco
                red_anterior = red
                memoria = psutil.virtual_memory()

                fila = {
                    "fecha_hora": datetime.now().isoformat(timespec="seconds"),
                    "cpu_total_pct": psutil.cpu_percent(),
                    "ram_total_pct": memoria.percent,
                    "ram_disponible_mb": round(memoria.available / 1024**2, 2),
                    "moodle_web_cpu_pct": medidas["Moodle_Web"][0],
                    "moodle_web_ram_mb": medidas["Moodle_Web"][1],
                    "moodle_bd_cpu_pct": medidas["Moodle_BD"][0],
                    "moodle_bd_ram_mb": medidas["Moodle_BD"][1],
                    "python_cpu_pct": medidas["Python_FastAPI"][0],
                    "python_ram_mb": medidas["Python_FastAPI"][1],
                    "python_procesos": len(grupos["Python_FastAPI"]),
                    "disco_lectura_mb_s": round(lectura, 3),
                    "disco_escritura_mb_s": round(escritura, 3),
                    "red_envio_mb_s": round(envio, 3),
                    "red_recepcion_mb_s": round(recepcion, 3),
                }

                escritor.writerow(fila)
                f.flush()
                filas += 1

                print(
                    f"CPU total: {fila['cpu_total_pct']:5.1f}% "
                    f"| Moodle web: {fila['moodle_web_ram_mb']:7.1f} MB "
                    f"| BD: {fila['moodle_bd_ram_mb']:7.1f} MB "
                    f"| Python: {fila['python_ram_mb']:7.1f} MB"
                )

        except KeyboardInterrupt:
            pass

    metadatos["fin"] = datetime.now().isoformat(timespec="seconds")
    metadatos["filas"] = filas
    escribir_metadatos(archivo_meta, metadatos)
    print("\nMedición finalizada.")
    print("Resultados:", archivo)
    return 0


if __name__ == "__main__":
    sys.exit(main())
