# Instalación rápida: solo el plugin (Gemini directo)

Desde la versión 0.5.0 el plugin puede hacer **todo el proceso dentro de Moodle**, sin servicio intermedio:

```
Moodle (con el plugin) ── lee la entrega con la File API ──▶ Gemini (HTTPS) ──▶ comentario + transcripción en Moodle
```

No hace falta `php-service/`, ni un `.env`, ni un token de servicios web, ni cambiar «Seguridad HTTP», ni abrir el puerto 8000. Sirve para cualquier Moodle 4.5 o superior: el paquete local de Windows, Docker o un Moodle existente.

**Qué está probado:** Moodle 4.5.13+ en Windows (paquete oficial), con Gemini real: texto en línea, PDF de 1 y 2 páginas, una entrega mixta (2 PDF, un `.txt` y texto en línea), un PDF real escaneado, y los límites de páginas, tamaño y tipo de archivo. También la actualización desde la 0.4.0.

**Qué no está probado:** Linux, macOS, Moodle 5.x, sitios detrás de un proxy y la instalación del zip desde la web con esta versión (el mecanismo es el mismo que el de las versiones anteriores, que sí se probó).

## 1. Requisitos

| Requisito | Detalle |
|---|---|
| Moodle | 4.5 o superior, con acceso de administrador del sitio |
| Salida a internet | El servidor de Moodle debe poder abrir `https://generativelanguage.googleapis.com` (si el sitio usa proxy, Moodle lo usa) |
| Clave de Gemini | De [Google AI Studio](https://aistudio.google.com/apikey) |
| Tiempo de ejecución | Una evaluación tarda de 5 a 30 s (más con PDF largos). El límite del servidor web (`max_execution_time` de PHP-FPM, `Timeout` de Apache o Nginx) debe ser mayor; con 300 s sobra |

## 2. Obtén el zip del plugin

Si está publicado, descárgalo de *Releases* en GitHub (`assignfeedback_aiocr_<versión>.zip`). Si no, desde una copia del repositorio:

```bash
php scripts/package_plugin.php
```

Queda en `dist/`. **No subas a Moodle el zip de todo el repositorio** («Code → Download ZIP»): Moodle lo rechaza.

## 3. Instala el plugin

Administración del sitio → Extensiones → **Instalar complementos** → sube el zip → «Actualizar base de datos Moodle ahora».

- El aviso «MATURITY_ALPHA» es normal: el plugin es un prototipo.
- Si Moodle no deja instalar desde la web (permisos o `$CFG->disableupdateautodeploy`), descomprime el zip en `<moodle>/mod/assign/feedback/`, de modo que quede `.../feedback/aiocr/version.php`, y entra a Administración del sitio → **Notificaciones**. Más detalle en [instalacion-plugin.md, paso 3](instalacion-plugin.md#3-instala-el-plugin).

## 4. Configura el plugin

Administración del sitio → Extensiones → Módulos de actividades → Tarea → **Extensiones de retroalimentación** → Auto Evaluar:

| Ajuste | Valor |
|---|---|
| Proveedor de IA | **Gemini (directo, sin servicio externo)** (es el valor por defecto en instalaciones nuevas) |
| Clave de la API de Gemini | Tu clave de Google AI Studio |
| Modelo de Gemini | `gemini-3.5-flash-lite` (por defecto) |
| Tiempo de espera por llamada | `120` s |
| Tamaño máximo por archivo / Páginas máximas / Evaluaciones simultáneas | `20` MB / `20` / `3` |
| Guardar transcripciones | Activado: la transcripción, los tokens y la duración quedan en la tabla `assignfeedback_aiocr` |

La sección «Servicio externo (opcional)» se deja como está: solo la usa el proveedor «Servicio externo».

> La clave de Gemini queda en la base de datos de Moodle y la pueden ver los administradores del sitio.

## 5. Actívalo en cada tarea

Tarea → Configuración → **Tipos de retroalimentación** → activa «Auto Evaluar» junto a «Comentarios de retroalimentación» → Guardar.

Pueden usar el botón los roles Profesor, Profesor sin permiso de edición y Gestor (capacidad `assignfeedback/aiocr:generate`).

## 6. Opcional: rúbrica y contexto del profesor

Para que la evaluación siga lo que pides en cada tarea:

1. **Material** (cualquiera, o varios):
   - una **rúbrica o guía de evaluación** de Moodle: Tarea → Calificación avanzada;
   - en los ajustes de la tarea, sección Auto Evaluar: **«Contexto para la IA»** (respuestas esperadas, criterios, qué no penalizar) y **«Archivos del profesor para la IA»** (rúbrica, enunciado o solucionario en PDF, PNG o JPG);
   - la descripción y los archivos adicionales de la tarea también se usan.
2. **Preparar:** en los ajustes de la tarea o en la pantalla de calificación, **«Preparar rúbrica con IA»** → «Generar con IA». Gemini ordena el material en preguntas, respuestas esperadas, criterios y vacíos por resolver.
3. **Revisar:** corrige el texto (sobre todo las respuestas marcadas «propuesta por la IA, revisar» y la sección «Vacíos o ambigüedades») y pulsa «Guardar cambios».
4. Desde ahí, cada evaluación usa ese contexto y termina con una **valoración por criterio** (nivel sugerido y justificación). La nota la pones tú.

Si cambias la rúbrica, el texto o los archivos después de preparar, la pantalla de calificación avisa que el contexto está desactualizado. Sin preparar, se usa el material tal cual (sin los archivos). Los estudiantes no ven este material.

> **¿Sin clave de Gemini?** Desde la 0.7.0 el proveedor «Modelo local (Ollama)» usa un modelo propio, como Gemma: ver [modelo-local-gemma.md](modelo-local-gemma.md).

## 7. Prueba

Tarea → Ver todas las entregas → Calificar → **Auto Evaluar** sobre una entrega en PDF, imagen o texto en línea. La retroalimentación aparece en «Comentarios de retroalimentación»; la calificación no cambia.

## Qué hace el plugin con la entrega

1. Lee los archivos con la File API de Moodle. Solo PDF, PNG y JPG; los demás (por ejemplo `.docx`) se omiten y quedan anotados.
2. Cuenta las páginas de toda la entrega y la rechaza si pasa del máximo, **antes** de llamar a Gemini.
3. Envía cada PDF **entero** a Gemini (una llamada por archivo) y cada imagen en otra llamada, para transcribirlos. El texto en línea se usa tal cual.
4. Si algún archivo no se pudo transcribir, se detiene: no publica una evaluación incompleta.
5. Envía la transcripción completa a Gemini para evaluarla, junto con el contexto del profesor si lo hay, y guarda el resultado como comentario. Las reglas van como instrucciones de sistema y la entrega en un bloque aparte: el texto de la entrega dirigido a la IA se ignora y se señala.

Las instrucciones para Gemini (prompts) están en `classes/local/gemini/prompts.php`, dentro del plugin.

## Privacidad

Con este proveedor, los archivos y el texto en línea de la entrega se envían a Google (API de Gemini). El plugin lo declara en la API de privacidad de Moodle. Si tu institución lo exige, avísalo a los estudiantes.

## Problemas frecuentes

| Mensaje | Causa | Solución |
|---|---|---|
| «No se ha configurado la clave de la API de Gemini» | Falta la clave | Paso 4 |
| «Gemini: no se pudo conectar…» | El servidor no sale a internet, o el proxy no deja pasar | Revisa la salida a `generativelanguage.googleapis.com` y el proxy de Moodle |
| «Gemini: error 400/403…» | Clave inválida o modelo inexistente | Revisa la clave y el nombre del modelo |
| «Gemini: error 429…» | Se agotó la cuota de la clave | Espera, o usa una clave con más cuota |
| «Ya hay N evaluaciones en curso» | Se llegó al máximo de evaluaciones simultáneas | Intenta de nuevo, o sube el ajuste |
| La página se queda cargando y falla a los minutos | El servidor web cortó la solicitud | Sube el límite de tiempo del servidor web (requisitos) |
