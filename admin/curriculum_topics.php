<?php
require_once __DIR__ . '/_layout.php';
$pdo=admin_db();
admin_header('Curriculum','Gespeicherte Lehrplan-Kontexte und zugeordnete Quizze.');
$topics=[];
try{$topics=$pdo->query("SELECT t.id,t.code,t.title,t.description,t.learning_goal,t.grade,s.name state_name,st.name school_type_name,sub.name subject_name,sub.icon subject_icon,COUNT(qtm.quiz_id) quiz_count FROM curriculum_topics t JOIN states s ON s.id=t.state_id JOIN school_types st ON st.id=t.school_type_id JOIN subjects sub ON sub.id=t.subject_id LEFT JOIN quiz_topic_map qtm ON qtm.topic_id=t.id GROUP BY t.id ORDER BY s.sort_order,st.sort_order,t.grade,sub.sort_order,t.sort_order,t.title")->fetchAll();}catch(Throwable $e){$error=$e->getMessage();}
?>
<?php if(!empty($error)): ?><div class="alert alert-danger"><?=admin_h($error)?></div><?php endif; ?>
<div class="card-soft admin-table-card"><table class="table table-hover"><thead><tr><th>Thema</th><th>Kontext</th><th>Quizze</th></tr></thead><tbody>
<?php foreach($topics as $t): ?><tr><td><strong><?=admin_h($t['title'])?></strong><small class="text-muted d-block"><?=admin_h($t['description'])?></small><?php if($t['learning_goal']): ?><small class="d-block"><b>Lernziel:</b> <?=admin_h($t['learning_goal'])?></small><?php endif; ?></td><td><?=admin_h($t['state_name'])?> · <?=admin_h($t['school_type_name'])?> · Klasse <?=(int)$t['grade']?> · <?=admin_h(($t['subject_icon']?:'').' '.$t['subject_name'])?></td><td><?=(int)$t['quiz_count']?></td></tr><?php endforeach; if(!$topics): ?><tr><td colspan="3" class="p-4 text-muted">Noch keine Curriculum-Themen.</td></tr><?php endif; ?></tbody></table></div>
<?php admin_footer(); ?>