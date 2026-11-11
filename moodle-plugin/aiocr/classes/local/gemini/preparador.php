<?php
// This file is part of Moodle - https://moodle.org/
//
// Paso «Preparar rúbrica»: con el material del profesor (descripción de la
// tarea, rúbrica de Moodle, contexto escrito y archivos) Gemini arma UNA vez
// un contexto ordenado (preguntas, respuestas esperadas, criterios). El
// profesor lo revisa y corrige, y se usa en la evaluación de cada estudiante.
// No depende de Moodle.

namespace assignfeedback_aiocr\local\gemini;

defined('MOODLE_INTERNAL') || die();

/**
 * Prepara el contexto del profesor.
 */
final class preparador {

    /**
     * @param cliente_gemini $gemini cliente configurado
     */
    public function __construct(private readonly cliente_gemini $gemini) {
    }

    /**
     * @param string $material texto del profesor (ver contexto_profesor::fuentes)
     * @param array $archivos lista de ['nombre' => string, 'mimetype' => string, 'contenido' => string]
     * @return array [contexto preparado, consumo de tokens]
     * @throws evaluacion_exception nocontext o gemini
     */
    public function preparar(string $material, array $archivos = []): array {
        if (trim($material) === '' && !$archivos) {
            throw new evaluacion_exception('nocontext');
        }
        $mensaje = "MATERIAL DEL PROFESOR\n\n" . trim($material);
        if ($archivos) {
            $mensaje .= "\n\nArchivos adjuntos, en este orden: "
                . implode(', ', array_map(static fn ($a) => $a['nombre'], $archivos)) . '.';
        }
        [$texto, $consumo] = $this->gemini->consultar_con(prompts::PREPARAR, $mensaje, $archivos);
        if ($texto === '') {
            throw new evaluacion_exception('gemini', 'la preparación está vacía');
        }
        return [self::limpiar($texto), $consumo];
    }

    /** Quita un bloque de código que envuelva toda la respuesta (```markdown ... ```). */
    public static function limpiar(string $texto): string {
        $texto = trim($texto);
        if (preg_match('/^```[a-zA-Z]*\R(.*)\R```$/s', $texto, $m)) {
            $texto = trim($m[1]);
        }
        return $texto;
    }
}
