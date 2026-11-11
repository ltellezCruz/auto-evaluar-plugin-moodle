# Modelo local con Gemma (Ollama), sin claves

Desde la versión 0.7.0 el plugin puede evaluar con un **modelo propio** en lugar de la API de Gemini: por ejemplo **Gemma 4 31B**, servido con [Ollama](https://ollama.com) en el mismo servidor o en otro equipo de la red. No usa claves ni tokens y las entregas no salen hacia Google. Está pensado para **pruebas y comparación** con Gemini.

```
Moodle (plugin) ── páginas como imágenes + instrucciones ──▶ Ollama (Gemma) ──▶ comentario en Moodle
```

## Qué es «el modelo» y por qué no va dentro del plugin

- El modelo es **un solo archivo** grande (formato GGUF). Gemma 4 31B, comprimido a 4 bits, pesa **unos 18 a 20 GB**.
- PHP y Moodle no pueden ejecutar un modelo: lo hace **Ollama**, que lo carga en la memoria de la tarjeta gráfica y responde por `http://<equipo>:11434`.
- Por eso son tres piezas: el zip del plugin (unos 100 KB), el instalador de Ollama y el archivo del modelo, que Ollama descarga con un comando.

## Requisitos del equipo que corre el modelo

| Modelo | Memoria aproximada | Equipo |
|---|---|---|
| Gemma 4 31B (4 bits) | ~20 GB para el modelo, más el contexto | GPU de **24 GB** (por ejemplo RTX 3090/4090) o un Mac con 32 GB o más de memoria unificada |
| Gemma pequeño (por ejemplo de 4B) | ~3 a 5 GB | Cualquier PC con 8 GB de RAM o más; sin GPU funciona, pero lento |

Verifica en [ollama.com/library](https://ollama.com/library) la etiqueta exacta del modelo (por ejemplo `gemma4:31b`), que acepte imágenes y cuánta memoria pide. Las cifras de arriba son aproximadas.

> El PC donde se desarrolló el plugin (11,8 GB de RAM, GPU de 2 GB) **no puede** correr la versión 31B. Sirve para probar el mecanismo con un Gemma pequeño.

## Pasos

### 1. Instalar Ollama y descargar el modelo (en el equipo del modelo)

Instala Ollama desde [ollama.com/download](https://ollama.com/download) y descarga el modelo:

```bash
ollama pull gemma4:31b
```

Comprueba que responde: `ollama list` debe mostrarlo, y `http://127.0.0.1:11434` debe decir «Ollama is running».

Si Ollama corre en **otro equipo** de la red, haz que escuche en la red (variable de entorno `OLLAMA_HOST=0.0.0.0`) y limita con el firewall el puerto 11434 a la IP del servidor de Moodle: Ollama no pide contraseña.

### 2. Permitir la dirección en Moodle

Administración del sitio → General → Seguridad → **Seguridad HTTP**:
- **Puertos cURL permitidos:** agrega `11434`.
- **Lista de direcciones cURL bloqueadas:** quita la entrada que cubre la dirección de Ollama (`127.0.0.0/8` y `localhost` si está en el mismo servidor, o el rango privado de su IP).

Sin esto, la evaluación falla con «Modelo local: no se pudo conectar…: Esta URL está bloqueada».

### 3. PDF a imágenes (en el servidor de Moodle)

Los modelos locales no leen PDF: el plugin convierte cada página en una imagen y la transcribe por separado (el algoritmo original de una página a la vez). Para eso necesita **una** de estas dos:
- **Ghostscript**: instálalo y pon su ruta en Administración del sitio → Servidor → Rutas del sistema → **Ruta a ghostscript** (en Windows, algo como `C:\Program Files\gs\gs10.xx\bin\gswin64c.exe`; en Linux, `/usr/bin/gs`). Es la misma que usa Moodle para anotar PDF en las tareas.
- la extensión **imagick** de PHP.

La página de ajustes del plugin dice si el servidor puede convertir PDF. Sin conversor, con el modelo local solo se evalúan imágenes (PNG, JPG) y texto en línea.

### 4. Configurar el plugin

Extensiones → Tarea → Extensiones de retroalimentación → Auto Evaluar:

| Ajuste | Valor |
|---|---|
| Proveedor de IA | **Modelo local (Ollama, p. ej. Gemma), sin clave** |
| URL de Ollama | `http://127.0.0.1:11434` o la del otro equipo |
| Modelo local | La etiqueta de `ollama list`, por ejemplo `gemma4:31b` |
| Tiempo de espera por llamada | `600` s (un modelo local es mucho más lento) |
| Ventana de contexto | `16384` (el valor por defecto de Ollama es corto para estas instrucciones) |
| Resolución de las páginas de PDF | `150` DPI |

La rúbrica y el contexto del profesor (versión 0.6.0) también funcionan con el modelo local. «Preparar rúbrica con IA» usa el mismo modelo.

## Límites a tener en cuenta

- **Tiempo:** una evaluación hace una llamada por página más una para evaluar. Con una GPU adecuada, decenas de segundos; sin GPU, minutos. El servidor web corta las solicitudes largas (300 s en el paquete de Moodle para Windows): con un modelo lento, sube ese límite o evalúa entregas cortas.
- **Calidad:** un modelo local, sobre todo uno pequeño, transcribe y evalúa peor que Gemini. Compara con el mismo conjunto de entregas antes de sacar conclusiones.
- **Probado:** con un Ollama simulado dentro del Moodle local (formato de la solicitud, instrucciones de sistema, contexto del profesor, bloqueo del puerto 11434 y el aviso cuando falta el conversor de PDF). **No se ha probado con Gemma real** ni con Ghostscript.
