<?php
require_once __DIR__ . '/_layout.php';
$pdo=admin_db();
$quizId=(int)($_GET['quiz_id']??0);
if(!$quizId){ header('Location: quizzes.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['action']??'')==='publish_quiz'){
    $pdo->prepare("UPDATE quizzes SET status='published', is_active=1 WHERE id=:id")->execute(['id'=>$quizId]);
    $pdo->prepare("UPDATE questions SET status='published' WHERE quiz_id=:id AND status='draft'")->execute(['id'=>$quizId]);
    header('Location: quiz_questions.php?quiz_id='.$quizId.'&published=1'); exit;
  }
  foreach($_POST['questions']??[] as $qid=>$d){
    $pdo->prepare("UPDATE questions SET question_text=:qt, correct_answer=:ca, explanation=:ex, difficulty_manual=:dm, status=:st WHERE id=:id")->execute(['qt'=>$d['question_text']??'','ca'=>$d['correct_answer']??'','ex'=>$d['explanation']??'','dm'=>($d['difficulty_manual']??'')!==''?(float)$d['difficulty_manual']:null,'st'=>$d['status']??'draft','id'=>(int)$qid]);
    foreach($d['options']??[] as $oid=>$od){
      $txt=$od['option_text']??'';
      $pdo->prepare("UPDATE question_options SET option_text=:txt, is_correct=:isc WHERE id=:id")->execute(['txt'=>$txt,'isc'=>$txt===($d['correct_answer']??'')?1:0,'id'=>(int)$oid]);
    }
  }
  header('Location: quiz_questions.php?quiz_id='.$quizId.'&saved=1'); exit;
}
$stmt=$pdo->prepare("SELECT q.*,sub.name subject_name FROM quizzes q LEFT JOIN subjects sub ON sub.id=q.subject_id WHERE q.id=:id");$stmt->execute(['id'=>$quizId]);$quiz=$stmt->fetch();
if(!$quiz){ admin_header('Quiz nicht gefunden'); echo '<div class="alert alert-danger">Quiz nicht gefunden.</div>'; admin_footer(); exit; }
$stmt=$pdo->prepare("SELECT q.*,qs.times_correct,qs.times_wrong,qs.calculated_difficulty FROM questions q LEFT JOIN question_stats qs ON qs.question_id=q.id WHERE q.quiz_id=:id ORDER BY q.sort_order,q.id");$stmt->execute(['id'=>$quizId]);$questions=$stmt->fetchAll();
$options=[]; if($questions){$ids=array_column($questions,'id');$ph=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($ph) ORDER BY question_id,sort_order,id");$s->execute($ids);foreach($s->fetchAll() as $o){$options[(int)$o['question_id']][]=$o;}}
admin_header('Fragen Review', $quiz['title']);
?>
<?php if(isset($_GET['saved'])): ?><div class="alert alert-success">Gespeichert.</div><?php endif; ?><?php if(isset($_GET['ai_generated'])): ?><div class="alert alert-info">KI-Fragen wurden als Entwurf gespeichert. Bitte prüfen und veröffentlichen.</div><?php endif; ?><?php if(isset($_GET['published'])): ?><div class="alert alert-success">Quiz veröffentlicht.</div><?php endif; ?>
<div class="card-soft p-4 mb-4"><div class="d-flex justify-content-between gap-3 flex-wrap"><div><span class="badge <?= $quiz['status']==='published'?'text-bg-success':'text-bg-secondary' ?>"><?=admin_h($quiz['status'])?></span><h2 class="h4 fw-bold mt-2"><?=admin_h($quiz['title'])?></h2><p class="text-muted mb-0"><?=admin_h($quiz['description'])?></p></div><div class="d-flex gap-2 align-items-start"><a class="btn btn-outline-primary" href="quiz_ai_generate.php?quiz_id=<?=$quizId?>">✨ Fragen generieren</a><a class="btn btn-outline-secondary" href="quiz_visuals.php?quiz_id=<?=$quizId?>">🎨 Visuals</a><a class="btn btn-light" target="_blank" href="../quiz.php?key=<?=admin_h($quiz['quiz_key'])?>">Vorschau</a></div></div></div>
<form method="post">
<?php foreach($questions as $q): ?>
<div class="card-soft p-4 mb-3"><div class="d-flex justify-content-between"><div><span class="badge <?= $q['status']==='published'?'text-bg-success':'text-bg-secondary' ?>"><?=admin_h($q['status'])?></span><?php if((int)$q['ai_generated']===1):?> <span class="badge text-bg-primary">KI</span><?php endif;?></div><small class="text-muted"><?= (int)($q['times_correct']??0)?> richtig / <?= (int)($q['times_wrong']??0)?> falsch · Schwierigkeit <?=admin_h($q['calculated_difficulty']??$q['difficulty_calculated'])?></small></div><label class="form-label fw-bold mt-3">Frage</label><textarea class="form-control mb-3" name="questions[<?=(int)$q['id']?>][question_text]"><?=admin_h($q['question_text'])?></textarea><div class="row g-3"><div class="col-md-8"><label class="form-label fw-bold">Richtige Antwort</label><input class="form-control" name="questions[<?=(int)$q['id']?>][correct_answer]" value="<?=admin_h($q['correct_answer'])?>"></div><div class="col-md-4"><label class="form-label fw-bold">Schwierigkeit</label><input class="form-control" name="questions[<?=(int)$q['id']?>][difficulty_manual]" value="<?=admin_h($q['difficulty_manual']??'')?>"></div></div><label class="form-label fw-bold mt-3">Antwortoptionen</label><?php foreach($options[(int)$q['id']]??[] as $o): ?><input class="form-control mb-2" name="questions[<?=(int)$q['id']?>][options][<?=(int)$o['id']?>][option_text]" value="<?=admin_h($o['option_text'])?>"><?php endforeach; ?><label class="form-label fw-bold mt-2">Erklärung</label><textarea class="form-control mb-3" name="questions[<?=(int)$q['id']?>][explanation]"><?=admin_h($q['explanation'])?></textarea><label class="form-label fw-bold">Status</label><select class="form-select" name="questions[<?=(int)$q['id']?>][status]"><option value="draft" <?=$q['status']==='draft'?'selected':''?>>Entwurf</option><option value="published" <?=$q['status']==='published'?'selected':''?>>Veröffentlicht</option><option value="archived" <?=$q['status']==='archived'?'selected':''?>>Archiviert</option></select></div>
<?php endforeach; if(!$questions): ?><div class="card-soft p-4 text-muted">Noch keine Fragen. Nutze „Fragen generieren“.</div><?php endif; ?>
<div class="d-flex gap-2 mt-4"><button class="btn btn-primary btn-lg">Speichern</button><button class="btn btn-success btn-lg" name="action" value="publish_quiz" type="submit">Quiz & Entwürfe veröffentlichen</button></div></form>
<?php admin_footer(); ?>