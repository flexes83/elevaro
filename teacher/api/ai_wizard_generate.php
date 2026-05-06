<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Ungültige Anfrage.');
    @set_time_limit(120);

    $teacherId = teacher_current_user_id();
    $classId = (int)($_POST['class_id'] ?? 0);
    $mode = ($_POST['mode'] ?? 'quiz') === 'listening' ? 'listening' : 'quiz';
    elevaro_teacher_ai_class_for_teacher($classId, $teacherId);
    $sourceText = trim((string)($_POST['source_text'] ?? ''));
    $extraPrompt = trim((string)($_POST['extra_prompt'] ?? ''));
    $materialGoal = (string)($_POST['material_goal'] ?? 'auto');
    $goalHints = [
        'auto' => 'Materialziel: KI entscheidet anhand des Materials, ob Inhalte abgefragt oder ähnliche Übungen erstellt werden sollen.',
        'content' => 'Materialziel: Inhalte verstehen und abfragen. Erstelle Fragen zum fachlichen Inhalt, nicht nur zum Aufgabenformat.',
        'practice' => 'Materialziel: Ähnliche Übungen erstellen. Trainiere den gleichen Aufgabentyp bzw. die gleiche Kompetenz, statt zufällige Beispielsatz-Inhalte abzufragen.',
        'vocabulary' => 'Materialziel: Vokabeltraining. Bewahre bei Fremdsprachen die Zielsprache und erstelle passende Vokabel-/Satzergänzungsfragen.',
        'grammar' => 'Materialziel: Grammatiktraining. Trainiere die zugrunde liegende Struktur, z. B. Pronomen, Zeitformen oder Satzbau.',
    ];
    if (isset($goalHints[$materialGoal])) {
        $extraPrompt = trim($extraPrompt . "

" . $goalHints[$materialGoal]);
    }
    $files = elevaro_teacher_ai_collect_files('source_files');

    if ($sourceText === '' && !$files) {
        throw new RuntimeException('Bitte Material hochladen oder einen Text/eine Aufgabenstellung eingeben.');
    }

    $draftId = elevaro_teacher_ai_create_split_draft($teacherId, $classId, $mode, $sourceText, $extraPrompt, $files);

    elevaro_teacher_ai_json_response([
        'ok' => true,
        'pending' => true,
        'draft_id' => $draftId,
        'message' => 'Die KI-Erstellung läuft mehrstufig im Hintergrund.',
    ]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
