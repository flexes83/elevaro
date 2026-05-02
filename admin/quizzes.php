<?php
require_once __DIR__ . '/_layout.php';
$pdo=admin_db();
admin_header('Quizze','Quizze prüfen, veröffentlichen und visuell ausarbeiten.');
$rows=[];
try{$rows=$pdo->query("SELECT q.id,q.title,q.description,q.status,q.is_active,q.grade,q.theme_color_1,q.theme_color_2,q.theme_emoji,sub.name subject_name,COUNT(qu.id) questions FROM quizzes q LEFT JOIN subjects sub ON sub.id=q.subject_id LEFT JOIN questions qu ON qu.quiz_id=q.id GROUP BY q.id ORDER BY q.created_at DESC,q.id DESC")->fetchAll();}catch(Throwable $e){$error=$e->getMessage();}
?>
<?php if(!empty($error)): ?><div class="alert alert-danger"><?=admin_h($error)?></div><?php endif; ?>
<div class="card-soft admin-table-card">
<table class="table table-hover">
<thead><tr><th></th><th>Quiz</th><th>Status</th><th>Fragen</th><th></th></tr></thead><tbody>
<?php foreach($rows as $q): ?>
<tr>
<td style="width:90px"><div class="quiz-visual-mini" style="--c1:<?=admin_h($q['theme_color_1']?:'#5a4ff3')?>;--c2:<?=admin_h($q['theme_color_2']?:'#8b7cff')?>"><?=admin_h($q['theme_emoji']?:'🎯')?></div></td>
<td><strong><?=admin_h($q['title'])?></strong><small class="text-muted d-block"><?=admin_h($q['subject_name'])?> · Klasse <?=admin_h($q['grade'])?></small></td>
<td><span class="badge <?= $q['status']==='published'?'text-bg-success':'text-bg-secondary' ?>"><?=admin_h($q['status'])?></span></td>
<td><?=(int)$q['questions']?></td>
<td class="text-end"><div class="btn-group"><a class="btn btn-sm btn-outline-primary" href="quiz_questions.php?quiz_id=<?=(int)$q['id']?>">Review</a><a class="btn btn-sm btn-outline-secondary" href="quiz_visuals.php?quiz_id=<?=(int)$q['id']?>">Visuals</a></div></td>
</tr>
<?php endforeach; if(!$rows): ?><tr><td colspan="5" class="p-4 text-muted">Noch keine Quizze vorhanden.</td></tr><?php endif; ?>
</tbody></table></div>
<?php admin_footer(); ?>