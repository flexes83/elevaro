<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/simple_pdf.php';

$pdo = teacher_db();
$class = teacher_selected_class();
if (!$class) {
    http_response_code(404);
    echo 'Klasse nicht gefunden.';
    exit;
}

$questionIds = $_POST['question_ids'] ?? $_GET['question_ids'] ?? [];
if (is_string($questionIds)) {
    $questionIds = explode(',', $questionIds);
}
$questionIds = array_values(array_unique(array_filter(array_map('intval', (array)$questionIds))));
if (!$questionIds) {
    http_response_code(400);
    echo 'Bitte mindestens eine Frage auswählen.';
    exit;
}

$classId = (int)$class['id'];
$classLabel = teacher_class_label($class);

// Only allow questions from quizzes that are actually assigned to this teacher class.
$ph = implode(',', array_fill(0, count($questionIds), '?'));
$stmt = $pdo->prepare("\n    SELECT q.id, q.question_text, q.type, q.quiz_id\n    FROM questions q\n    JOIN teacher_class_quizzes tcq ON tcq.quiz_id = q.quiz_id AND tcq.class_id = ?\n    WHERE q.id IN ($ph)\n    ORDER BY FIELD(q.id, $ph)\n");
$stmt->execute(array_merge([$classId], $questionIds, $questionIds));
$questions = $stmt->fetchAll();

if (!$questions) {
    http_response_code(404);
    echo 'Keine freigegebenen Fragen gefunden.';
    exit;
}

$optionsByQuestion = [];
$ids = array_map(static fn($q) => (int)$q['id'], $questions);
$oph = implode(',', array_fill(0, count($ids), '?'));
$oStmt = $pdo->prepare("\n    SELECT question_id, option_text\n    FROM question_options\n    WHERE question_id IN ($oph)\n    ORDER BY question_id, sort_order, id\n");
$oStmt->execute($ids);
foreach ($oStmt->fetchAll() as $option) {
    $optionsByQuestion[(int)$option['question_id']][] = (string)$option['option_text'];
}

$pdf = new SimpleTeacherPdf('Test', $classLabel);
foreach ($questions as $index => $question) {
    $pdf->question($index + 1, (string)$question['question_text'], $optionsByQuestion[(int)$question['id']] ?? []);
}

$filename = 'elevaro-test-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $classLabel)) . '.pdf';
$pdf->output($filename);
