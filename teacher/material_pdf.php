<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/simple_pdf.php';

$pdo = teacher_db();
$teacherId = teacher_current_user_id();
$customQuizId = (int)($_GET['custom_quiz_id'] ?? 0);
if ($customQuizId <= 0) {
    http_response_code(400);
    echo 'Arbeitsblatt fehlt.';
    exit;
}

$stmt = $pdo->prepare("\n    SELECT cq.*, tc.name AS class_name, tc.grade AS class_grade, tc.subject_code AS class_subject_code\n    FROM teacher_custom_quizzes cq\n    LEFT JOIN teacher_classes tc ON tc.id = cq.class_id\n    WHERE cq.id = :id AND cq.teacher_id = :teacher_id\n    LIMIT 1\n");
$stmt->execute(['id' => $customQuizId, 'teacher_id' => $teacherId]);
$custom = $stmt->fetch();
if (!$custom) {
    http_response_code(404);
    echo 'Arbeitsblatt nicht gefunden.';
    exit;
}

$stmt = $pdo->prepare("\n    SELECT q.id, q.question_text, q.type\n    FROM teacher_custom_quiz_questions cqq\n    JOIN questions q ON q.id = cqq.source_question_id\n    WHERE cqq.custom_quiz_id = :custom_quiz_id\n    ORDER BY cqq.sort_order ASC, cqq.id ASC\n");
$stmt->execute(['custom_quiz_id' => $customQuizId]);
$questions = $stmt->fetchAll() ?: [];
if (!$questions) {
    http_response_code(404);
    echo 'Dieses Arbeitsblatt enthält noch keine Fragen.';
    exit;
}

$optionsByQuestion = [];
$ids = array_map(static fn($q) => (int)$q['id'], $questions);
$ph = implode(',', array_fill(0, count($ids), '?'));
$oStmt = $pdo->prepare("\n    SELECT question_id, option_text\n    FROM question_options\n    WHERE question_id IN ($ph)\n    ORDER BY question_id, sort_order, id\n");
$oStmt->execute($ids);
foreach ($oStmt->fetchAll() ?: [] as $option) {
    $optionsByQuestion[(int)$option['question_id']][] = (string)$option['option_text'];
}

$title = trim((string)($custom['title'] ?? 'Arbeitsblatt')) ?: 'Arbeitsblatt';
$classLabel = trim((string)($custom['class_name'] ?? ''));
if ($classLabel === '') {
    $parts = [];
    if (!empty($custom['class_subject_code'])) $parts[] = strtoupper((string)$custom['class_subject_code']);
    if (!empty($custom['class_grade'])) $parts[] = 'Klasse ' . (string)$custom['class_grade'];
    $classLabel = $parts ? implode(' · ', $parts) : 'Unterrichtsmaterial';
}

$pdf = new SimpleTeacherPdf($title, $classLabel);
foreach ($questions as $index => $question) {
    $pdf->question($index + 1, (string)$question['question_text'], $optionsByQuestion[(int)$question['id']] ?? []);
}

$filename = 'elevaro-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)) . '.pdf';
$pdf->output($filename);
