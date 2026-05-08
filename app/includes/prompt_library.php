<?php

declare(strict_types=1);

/**
 * Elevaro Prompt Library
 *
 * Baut KI-Prompts modular aus Core-Regeln, Fachregeln und Aufgabentyp-Regeln zusammen.
 * Dadurch muss der Wizard nicht mehr einen einzigen Monsterprompt für alle Fächer verwenden.
 */

if (!function_exists('elevaro_prompt_library_root')) {
    function elevaro_prompt_library_root(): string
    {
        return dirname(__DIR__, 2) . '/prompts';
    }
}

if (!function_exists('elevaro_prompt_library_read')) {
    function elevaro_prompt_library_read(string $group, string $name): string
    {
        $safeGroup = preg_replace('/[^a-z0-9_\-]/i', '', $group);
        $safeName = preg_replace('/[^a-z0-9_\-]/i', '', $name);
        if (!$safeGroup || !$safeName) {
            return '';
        }

        $path = elevaro_prompt_library_root() . '/' . $safeGroup . '/' . $safeName . '.md';
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        return trim((string)file_get_contents($path));
    }
}

if (!function_exists('elevaro_prompt_library_normalize_subject')) {
    function elevaro_prompt_library_normalize_subject(?string $subjectCode, ?string $subjectLabel = null): string
    {
        $raw = mb_strtolower(trim((string)($subjectCode ?: $subjectLabel)), 'UTF-8');
        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'mathematik' => 'math', 'mathe' => 'math', 'math' => 'math',
            'deutsch' => 'german', 'german' => 'german',
            'englisch' => 'english', 'english' => 'english',
            'franzoesisch' => 'french', 'französisch' => 'french', 'french' => 'french',
            'biologie' => 'biology', 'biology' => 'biology',
            'geschichte' => 'history', 'history' => 'history',
            'geographie' => 'geography', 'erdkunde' => 'geography', 'geography' => 'geography',
            'sachunterricht' => 'general_studies',
            'politik' => 'politics', 'gemeinschaftskunde' => 'politics', 'politics' => 'politics',
            'physik' => 'physics', 'physics' => 'physics',
            'chemie' => 'chemistry', 'chemistry' => 'chemistry',
        ];
        $raw = strtr($raw, $map);
        $raw = preg_replace('/[^a-z0-9]+/', '_', $raw) ?: 'default';
        return trim($raw, '_') ?: 'default';
    }
}

if (!function_exists('elevaro_prompt_library_detect_exercise_type')) {
    function elevaro_prompt_library_detect_exercise_type(array $context = []): string
    {
        $mode = (string)($context['mode'] ?? '');
        if ($mode === 'listening') {
            return 'listening';
        }

        $haystack = mb_strtolower(json_encode([
            $context['material_type'] ?? '',
            $context['task_intent'] ?? '',
            $context['content_mode'] ?? '',
            $context['generation_strategy'] ?? '',
            $context['detected_skills'] ?? [],
            $context['topics'] ?? [],
            $context['question'] ?? '',
        ], JSON_UNESCAPED_UNICODE), 'UTF-8');

        if (preg_match('/word\s*pair|wordpair|wortpaar|wortpaare|word bank|wordbank|wortbank|pronoun pair|personalpronomen|possessivpronomen/u', $haystack)) {
            return 'word_pairs';
        }
        if (preg_match('/luecke|lücke|cloze|fill.?in|gap|satzergänzung|satzerganzung/u', $haystack)) {
            return 'cloze';
        }
        if (preg_match('/vocab|vokabel|übersetzung|uebersetzung|translation/u', $haystack)) {
            return 'vocabulary';
        }
        if (preg_match('/bild|image|diagramm|karte|map|chart/u', $haystack)) {
            return 'image_analysis';
        }

        return 'multiple_choice';
    }
}

if (!function_exists('elevaro_prompt_library_build')) {
    function elevaro_prompt_library_build(array $context = []): string
    {
        $stage = (string)($context['stage'] ?? 'generation');
        $subject = elevaro_prompt_library_normalize_subject(
            $context['subject_code'] ?? null,
            $context['subject_label'] ?? null
        );
        $exerciseType = (string)($context['exercise_type'] ?? elevaro_prompt_library_detect_exercise_type($context));

        $parts = [];
        $parts[] = elevaro_prompt_library_read('core', 'base_generation');

        if ($stage === 'analysis') {
            $parts[] = elevaro_prompt_library_read('core', 'worksheet_context');
        }
        if (in_array($stage, ['questions', 'suggest_options', 'plausibility'], true)) {
            $parts[] = elevaro_prompt_library_read('core', 'quality_check');
        }

        $subjectPrompt = elevaro_prompt_library_read('subjects', $subject);
        if ($subjectPrompt === '') {
            $subjectPrompt = elevaro_prompt_library_read('subjects', 'default');
        }
        $parts[] = $subjectPrompt;

        $exercisePrompt = elevaro_prompt_library_read('exercise_types', $exerciseType);
        if ($exercisePrompt === '') {
            $exercisePrompt = elevaro_prompt_library_read('exercise_types', 'multiple_choice');
        }
        $parts[] = $exercisePrompt;

        $stagePrompt = elevaro_prompt_library_read('core', 'stage_' . $stage);
        if ($stagePrompt !== '') {
            $parts[] = $stagePrompt;
        }

        $parts = array_values(array_filter(array_map('trim', $parts)));
        if (!$parts) {
            return '';
        }

        return "\n\nPROMPT-BIBLIOTHEK / VERBINDLICHE ZUSATZREGELN:\n" . implode("\n\n---\n\n", $parts) . "\n";
    }
}
