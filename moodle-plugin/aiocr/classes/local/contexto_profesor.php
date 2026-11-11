<?php
// This file is part of Moodle - https://moodle.org/

namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

use assignfeedback_aiocr\local\gemini\evaluador;
use assignfeedback_aiocr\local\gemini\pdf_paginas;
use assignfeedback_aiocr\local\gemini\preparador;

/**
 * contexto_profesor
 *
 * Reúne lo que el profesor definió para una tarea y que guía la evaluación:
 *   1. La rúbrica o guía de evaluación de Moodle (Calificación avanzada).
 *   2. Lo propio del plugin en los ajustes de la tarea: «Contexto para la
 *      IA» (texto) y archivos (rúbrica, enunciado, solucionario).
 *   3. El nombre, la descripción, las instrucciones y los archivos
 *      adicionales de la tarea.
 *
 * Con eso se puede «preparar» (Gemini lo ordena una vez y el profesor lo
 * revisa; ver prepare.php) o usar tal cual. Es contenido de confianza:
 * nunca se mezcla con lo que escribe el estudiante.
 *
 * Ajustes por tarea (assign_plugin_config del plugin):
 *   context      texto escrito por el profesor
 *   prepared     contexto preparado y revisado
 *   preparedhash huella de las fuentes al preparar (para avisar si cambiaron)
 *   preparedtime cuándo se preparó o editó
 */
class contexto_profesor {

    /** Área de archivos del profesor en este plugin. */
    public const AREA_ARCHIVOS = 'teacherfiles';

    /** Tipos de archivo del profesor que se envían a Gemini. */
    public const TIPOS_ARCHIVO = ['.pdf', '.png', '.jpg', '.jpeg'];

    /** @return \assign_feedback_plugin el plugin en esta tarea */
    public static function plugin(\assign $assign): \assign_feedback_plugin {
        return $assign->get_feedback_plugin_by_type('aiocr');
    }

    /**
     * Rúbrica o guía de evaluación definida en Moodle, como texto.
     *
     * @return array [método ('rubric', 'guide' o null), texto, número de criterios]
     */
    public static function rubrica_moodle(\assign $assign): array {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        $gestor = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
        $metodo = $gestor->get_active_method();
        if (!$metodo || !in_array($metodo, ['rubric', 'guide'], true)) {
            return [null, '', 0];
        }
        $controlador = $gestor->get_controller($metodo);
        if (!$controlador->is_form_defined()) {
            return [$metodo, '', 0];
        }
        $definicion = $controlador->get_definition();
        $texto = static fn ($html) => trim(html_to_text((string) $html, 0, false));
        $lineas = [];
        if (trim((string) ($definicion->name ?? '')) !== '') {
            $lineas[] = 'Nombre: ' . $definicion->name;
        }
        if ($texto($definicion->description ?? '') !== '') {
            $lineas[] = 'Descripción: ' . $texto($definicion->description);
        }
        $n = 0;
        if ($metodo === 'rubric') {
            foreach ($definicion->rubric_criteria ?? [] as $criterio) {
                $n++;
                $lineas[] = "- Criterio $n: " . $texto($criterio['description']);
                foreach ($criterio['levels'] ?? [] as $nivel) {
                    $lineas[] = '  - ' . (float) $nivel['score'] . ' puntos: ' . $texto($nivel['definition']);
                }
            }
        } else {
            foreach ($definicion->guide_criteria ?? [] as $criterio) {
                $n++;
                $lineas[] = "- Criterio $n: {$criterio['shortname']} (máximo " . (float) $criterio['maxscore'] . ' puntos)';
                if ($texto($criterio['description'] ?? '') !== '') {
                    $lineas[] = '  - Para el estudiante: ' . $texto($criterio['description']);
                }
                if ($texto($criterio['descriptionmarkers'] ?? '') !== '') {
                    $lineas[] = '  - Para el evaluador: ' . $texto($criterio['descriptionmarkers']);
                }
            }
        }
        return [$metodo, implode("\n", $lineas), $n];
    }

    /**
     * Todo el material del profesor para esta tarea.
     *
     * @return array ['texto' => string, 'archivos' => \stored_file[], 'omitidos' => string[],
     *                'hash' => string, 'metodo' => ?string, 'criterios' => int, 'contexto' => bool]
     */
    public static function fuentes(\assign $assign): array {
        $instancia = $assign->get_instance();
        $plano = static fn ($html) => trim(html_to_text((string) $html, 0, false));
        $partes = ['## Tarea: ' . format_string($instancia->name, true, ['context' => $assign->get_context()])];
        if ($plano($instancia->intro ?? '') !== '') {
            $partes[] = "## Descripción de la tarea\n" . $plano($instancia->intro);
        }
        if ($plano($instancia->activity ?? '') !== '') {
            $partes[] = "## Instrucciones de la actividad\n" . $plano($instancia->activity);
        }
        [$metodo, $rubrica, $criterios] = self::rubrica_moodle($assign);
        if ($rubrica !== '') {
            $partes[] = '## ' . ($metodo === 'guide' ? 'Guía de evaluación' : 'Rúbrica') . " definida en Moodle\n$rubrica";
        }
        $contexto = trim((string) self::plugin($assign)->get_config('context'));
        if ($contexto !== '') {
            $partes[] = "## Contexto escrito por el profesor\n$contexto";
        }

        // Archivos: los del plugin y los «archivos adicionales» de la tarea.
        $fs = get_file_storage();
        $contextid = $assign->get_context()->id;
        $candidatos = array_merge(
            array_values($fs->get_area_files($contextid, 'assignfeedback_aiocr', self::AREA_ARCHIVOS, 0, 'filename', false)),
            array_values($fs->get_area_files($contextid, 'mod_assign', ASSIGN_INTROATTACHMENT_FILEAREA, 0, 'filename', false))
        );
        $maxbytes = max(1, (int) get_config('assignfeedback_aiocr', 'max_file_mb') ?: 20) * 1024 * 1024;
        $archivos = [];
        $omitidos = [];
        foreach ($candidatos as $archivo) {
            if (evaluador::tipo_admitido((string) $archivo->get_mimetype(), $archivo->get_filename()) === null
                    || $archivo->get_filesize() > $maxbytes) {
                $omitidos[] = $archivo->get_filename();
            } else {
                $archivos[] = $archivo;
            }
        }

        $texto = implode("\n\n", $partes);
        $huella = sha1($texto . '|' . implode(',', array_map(static fn ($a) => $a->get_contenthash(), $archivos)));
        return [
            'texto' => $texto,
            'archivos' => $archivos,
            'omitidos' => $omitidos,
            'hash' => $huella,
            'metodo' => $metodo,
            'criterios' => $criterios,
            'contexto' => $contexto !== '',
        ];
    }

    /**
     * Contexto que se usa al evaluar a un estudiante.
     *
     * Si el profesor lo preparó, se usa esa versión revisada. Si no, el texto
     * de las fuentes (sin archivos, para no enviarlos en cada evaluación).
     *
     * @return array [texto, origen: 'preparado', 'preparado_desactualizado' o 'fuentes']
     */
    public static function para_evaluar(\assign $assign): array {
        $plugin = self::plugin($assign);
        $fuentes = self::fuentes($assign);
        $preparado = trim((string) $plugin->get_config('prepared'));
        if ($preparado !== '') {
            $vigente = $plugin->get_config('preparedhash') === $fuentes['hash'];
            return [$preparado, $vigente ? 'preparado' : 'preparado_desactualizado'];
        }
        return [$fuentes['texto'], 'fuentes'];
    }

    /** @return bool si hay un contexto preparado cuyas fuentes cambiaron después */
    public static function desactualizado(\assign $assign): bool {
        $plugin = self::plugin($assign);
        return trim((string) $plugin->get_config('prepared')) !== ''
            && $plugin->get_config('preparedhash') !== self::fuentes($assign)['hash'];
    }

    /**
     * Pide a Gemini el contexto preparado y lo guarda en la tarea.
     *
     * @return array [texto preparado, consumo de tokens]
     * @throws gemini\evaluacion_exception
     */
    public static function preparar(\assign $assign): array {
        $fuentes = self::fuentes($assign);
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        // El modelo del proveedor del sitio (Gemini o local). Si el proveedor
        // no evalúa dentro de Moodle (servicio externo, prueba), Gemini.
        $proveedor = provider_factory::get_provider();
        $modelo = $proveedor instanceof adaptador_modelo ? $proveedor->crear_modelo() : gemini_adapter::crear_cliente();

        $archivos = [];
        foreach ($fuentes['archivos'] as $a) {
            $tipo = evaluador::tipo_admitido((string) $a->get_mimetype(), $a->get_filename());
            if ($tipo === 'application/pdf' && !$modelo->acepta_pdf()) {
                // Modelo local: el PDF del profesor va como imágenes de sus páginas.
                $paginas = min(pdf_paginas::contar($a->get_content()), max(1, (int) get_config('assignfeedback_aiocr', 'max_pages') ?: 20));
                $dpi = max(72, min(300, (int) get_config('assignfeedback_aiocr', 'local_dpi') ?: 150));
                try {
                    $imagenes = rasterizador::convertir($a->get_content(), $dpi, $paginas);
                } catch (\RuntimeException $e) {
                    throw new gemini\evaluacion_exception('rasterize', (object) ['name' => $a->get_filename(), 'error' => $e->getMessage()]);
                }
                foreach ($imagenes as $k => $png) {
                    $archivos[] = ['nombre' => $a->get_filename() . ' (pág. ' . ($k + 1) . ')', 'mimetype' => 'image/png', 'contenido' => $png];
                }
            } else {
                $archivos[] = ['nombre' => $a->get_filename(), 'mimetype' => $tipo, 'contenido' => $a->get_content()];
            }
        }
        [$texto, $consumo] = (new preparador($modelo))->preparar($fuentes['texto'], $archivos);
        self::guardar($assign, $texto, $fuentes['hash']);
        return [$texto, $consumo];
    }

    /** Guarda el contexto preparado (o editado por el profesor). */
    public static function guardar(\assign $assign, string $texto, ?string $huella = null): void {
        $plugin = self::plugin($assign);
        $plugin->set_config('prepared', trim($texto));
        $plugin->set_config('preparedhash', $huella ?? self::fuentes($assign)['hash']);
        $plugin->set_config('preparedtime', (string) time());
    }

    /** Borra el contexto preparado (se vuelve a usar el material tal cual). */
    public static function borrar(\assign $assign): void {
        $plugin = self::plugin($assign);
        foreach (['prepared', 'preparedhash', 'preparedtime'] as $clave) {
            $plugin->set_config($clave, '');
        }
    }
}
