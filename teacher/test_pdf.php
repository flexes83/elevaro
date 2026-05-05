<?php
require_once __DIR__ . '/_bootstrap.php';

function pdf_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$pdo = teacher_db();
$class = teacher_selected_class();
if (!$class) { http_response_code(404); echo 'Klasse nicht gefunden.'; exit; }

$questionIds = array_values(array_filter(array_map('intval', $_POST['question_ids'] ?? $_GET['question_ids'] ?? [])));
if (!$questionIds) { echo 'Bitte mindestens eine Frage auswählen.'; exit; }

$ph = implode(',', array_fill(0, count($questionIds), '?'));
$stmt = $pdo->prepare("SELECT id, question_text, correct_answer, explanation FROM questions WHERE id IN ($ph) ORDER BY FIELD(id, $ph)");
$stmt->execute(array_merge($questionIds, $questionIds));
$questions = $stmt->fetchAll();

$optionsByQuestion = [];
if ($questions) {
    $ids = array_map(static fn($q) => (int)$q['id'], $questions);
    $oph = implode(',', array_fill(0, count($ids), '?'));
    $oStmt = $pdo->prepare("SELECT question_id, option_text, is_correct FROM question_options WHERE question_id IN ($oph) ORDER BY question_id, sort_order, id");
    $oStmt->execute($ids);
    foreach ($oStmt->fetchAll() as $option) {
        $optionsByQuestion[(int)$option['question_id']][] = $option;
    }
}
$classLabel = teacher_class_label($class);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Test – <?= pdf_h($classLabel) ?></title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;color:#172033;margin:32px;line-height:1.35}
  .head{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #172033;padding-bottom:18px;margin-bottom:24px}
  h1{font-size:26px;margin:0 0 6px}.muted{color:#697385}.field{border-bottom:1px solid #172033;min-width:220px;display:inline-block;height:22px}
  .question{break-inside:avoid;margin:0 0 22px;padding-bottom:16px;border-bottom:1px solid #d8dde5}.question h2{font-size:16px;margin:0 0 12px}.option{margin:7px 0}.box{display:inline-block;width:13px;height:13px;border:1px solid #172033;margin-right:8px;vertical-align:-2px}.solution{margin-top:8px;color:#167347;font-size:13px}.screen-actions{position:sticky;top:0;background:#fff;padding:12px 0;margin-bottom:12px}.screen-actions button{border:0;border-radius:999px;background:#6544ff;color:#fff;font-weight:700;padding:10px 16px}
  @media print{.screen-actions{display:none}.solution{display:none}body{margin:18mm}}
</style>
</head>
<body>
<div class="screen-actions"><button onclick="window.print()">PDF speichern / drucken</button> <span class="muted">Im Druckdialog „Als PDF sichern“ wählen.</span></div>
<div class="head">
  <div><h1>Test</h1><div class="muted"><?= pdf_h($classLabel) ?> · <?= count($questions) ?> Fragen</div></div>
  <div>Name: <span class="field"></span></div>
</div>
<?php foreach ($questions as $index => $question): ?>
  <?php $opts = $optionsByQuestion[(int)$question['id']] ?? []; ?>
  <section class="question">
    <h2><?= $index + 1 ?>. <?= pdf_h($question['question_text']) ?></h2>
    <?php if ($opts): foreach ($opts as $option): ?>
      <div class="option"><span class="box"></span><?= pdf_h($option['option_text']) ?></div>
    <?php endforeach; else: ?>
      <div style="height:70px;border:1px solid #d8dde5;border-radius:8px"></div>
    <?php endif; ?>
    <?php
      $solutions = array_values(array_map(static fn($o) => (string)$o['option_text'], array_filter($opts, static fn($o) => (int)$o['is_correct'] === 1)));
      if (!$solutions && !empty($question['correct_answer'])) $solutions[] = (string)$question['correct_answer'];
    ?>
    <?php if ($solutions): ?><div class="solution">Lösung: <?= pdf_h(implode(', ', $solutions)) ?></div><?php endif; ?>
  </section>
<?php endforeach; ?>
</body>
</html>
