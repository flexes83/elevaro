<?php
require_once __DIR__ . '/_layout.php';
$pdo=admin_db();
admin_header('KI-Auswahlen','Gespeicherte Themen- und Quizideen. Aus einer Auswahl kannst du mehrere Quizze erstellen, ohne den Prompt erneut zu senden.');
$batches=[];
try{$batches=$pdo->query("SELECT b.id,b.grade,b.focus,b.created_at,s.name state_name,st.name school_type_name,sub.name subject_name,COUNT(DISTINCT ts.id) topics,COUNT(qi.id) ideas FROM ai_topic_batches b JOIN states s ON s.id=b.state_id JOIN school_types st ON st.id=b.school_type_id JOIN subjects sub ON sub.id=b.subject_id LEFT JOIN ai_topic_suggestions ts ON ts.batch_id=b.id LEFT JOIN ai_quiz_ideas qi ON qi.topic_suggestion_id=ts.id WHERE b.status='success' GROUP BY b.id ORDER BY b.created_at DESC")->fetchAll();}catch(Throwable $e){$error=$e->getMessage();}
?>
<?php if(!empty($error)): ?><div class="alert alert-danger"><?=admin_h($error)?></div><?php endif; ?>
<div class="card-soft admin-table-card">
<table class="table table-hover">
<thead><tr><th>Kontext</th><th>Themen</th><th>Quizideen</th><th>Datum</th><th></th></tr></thead>
<tbody>
<?php foreach($batches as $b): ?>
<tr>
<td><strong><?=admin_h($b['state_name'])?> · <?=admin_h($b['school_type_name'])?> · Klasse <?=(int)$b['grade']?> · <?=admin_h($b['subject_name'])?></strong><?php if($b['focus']): ?><small class="d-block text-muted"><?=admin_h($b['focus'])?></small><?php endif; ?></td>
<td><?=(int)$b['topics']?></td><td><?=(int)$b['ideas']?></td><td><?=admin_h($b['created_at'])?></td>
<td class="text-end"><a class="btn btn-sm btn-primary" href="ai_curriculum_wizard.php?batch_id=<?=(int)$b['id']?>">Öffnen</a></td>
</tr>
<?php endforeach; if(!$batches): ?><tr><td colspan="5" class="p-4 text-muted">Noch keine gespeicherten KI-Auswahlen.</td></tr><?php endif; ?>
</tbody></table></div>
<?php admin_footer(); ?>