<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/includes/image_tools.php';

$pdo = admin_db();
$quizId = (int)($_GET['quiz_id'] ?? 0);

if (!$quizId) {
    header('Location: quizzes.php');
    exit;
}

function admin_load_question(PDO $pdo, int $questionId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $questionId]);
    return $stmt->fetch() ?: null;
}

function admin_load_option(PDO $pdo, int $optionId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM question_options WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $optionId]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'publish_quiz') {
        $pdo->prepare("UPDATE quizzes SET status='published', is_active=1 WHERE id=:id")->execute(['id' => $quizId]);
        $pdo->prepare("UPDATE questions SET status='published' WHERE quiz_id=:id AND status='draft'")->execute(['id' => $quizId]);
        header('Location: quiz_questions.php?quiz_id=' . $quizId . '&published=1');
        exit;
    }

    if ($action === 'generate_question_image') {
        $questionId = (int)($_POST['question_id'] ?? 0);
        $question = admin_load_question($pdo, $questionId);
        if ($question) {
            $prompt = trim($question['media_prompt'] ?? '') ?: 'Educational image for question: ' . $question['question_text'];
            $generated = elevaro_generate_and_store_image($prompt, 'question-images', $questionId);
            $pdo->prepare("UPDATE questions SET media_type='image', media_path=:path, media_alt=:alt, media_source='ai' WHERE id=:id")
                ->execute(['path' => $generated['path'], 'alt' => $question['question_text'], 'id' => $questionId]);
        }
        header('Location: quiz_questions.php?quiz_id=' . $quizId . '&image_generated=1');
        exit;
    }

    if ($action === 'generate_option_image') {
        $optionId = (int)($_POST['option_id'] ?? 0);
        $option = admin_load_option($pdo, $optionId);
        if ($option) {
            $prompt = trim($option['media_prompt'] ?? '') ?: 'Educational image representing: ' . $option['option_text'];
            $generated = elevaro_generate_and_store_image($prompt, 'answer-images', $optionId);
            $pdo->prepare("UPDATE question_options SET media_type='image', media_path=:path, media_alt=:alt, media_source='ai' WHERE id=:id")
                ->execute(['path' => $generated['path'], 'alt' => $option['option_text'], 'id' => $optionId]);
        }
        header('Location: quiz_questions.php?quiz_id=' . $quizId . '&image_generated=1');
        exit;
    }

    foreach ($_POST['questions'] ?? [] as $qid => $d) {
        $pdo->prepare("
            UPDATE questions
            SET question_text = :qt,
                media_type = :mt,
                media_path = :mp,
                media_alt = :ma,
                media_recommendation = :mr,
                media_prompt = :mpr,
                media_search_terms = :mst,
                correct_answer = :ca,
                explanation = :ex,
                difficulty_manual = :dm,
                status = :st
            WHERE id = :id
        ")->execute([
            'qt' => $d['question_text'] ?? '',
            'mt' => $d['media_type'] ?? 'none',
            'mp' => trim($d['media_path'] ?? '') ?: null,
            'ma' => trim($d['media_alt'] ?? '') ?: null,
            'mr' => $d['media_recommendation'] ?? 'none',
            'mpr' => trim($d['media_prompt'] ?? '') ?: null,
            'mst' => trim($d['media_search_terms'] ?? '') ?: null,
            'ca' => $d['correct_answer'] ?? '',
            'ex' => $d['explanation'] ?? '',
            'dm' => ($d['difficulty_manual'] ?? '') !== '' ? (float)$d['difficulty_manual'] : null,
            'st' => $d['status'] ?? 'draft',
            'id' => (int)$qid,
        ]);

        foreach ($d['options'] ?? [] as $oid => $od) {
            $txt = $od['option_text'] ?? '';
            $pdo->prepare("
                UPDATE question_options
                SET option_text = :txt,
                    media_type = :mt,
                    media_path = :mp,
                    media_alt = :ma,
                    media_prompt = :mpr,
                    media_search_terms = :mst,
                    is_correct = :isc
                WHERE id = :id
            ")->execute([
                'txt' => $txt,
                'mt' => $od['media_type'] ?? 'none',
                'mp' => trim($od['media_path'] ?? '') ?: null,
                'ma' => trim($od['media_alt'] ?? '') ?: null,
                'mpr' => trim($od['media_prompt'] ?? '') ?: null,
                'mst' => trim($od['media_search_terms'] ?? '') ?: null,
                'isc' => $txt === ($d['correct_answer'] ?? '') ? 1 : 0,
                'id' => (int)$oid,
            ]);
        }
    }

    header('Location: quiz_questions.php?quiz_id=' . $quizId . '&saved=1');
    exit;
}

$stmt = $pdo->prepare("SELECT q.*, sub.name subject_name FROM quizzes q LEFT JOIN subjects sub ON sub.id=q.subject_id WHERE q.id=:id");
$stmt->execute(['id' => $quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    admin_header('Quiz nicht gefunden');
    echo '<div class="alert alert-danger">Quiz nicht gefunden.</div>';
    admin_footer();
    exit;
}

$stmt = $pdo->prepare("SELECT q.*, qs.times_correct, qs.times_wrong, qs.calculated_difficulty FROM questions q LEFT JOIN question_stats qs ON qs.question_id=q.id WHERE q.quiz_id=:id ORDER BY q.sort_order,q.id");
$stmt->execute(['id' => $quizId]);
$questions = $stmt->fetchAll();

$options = [];
if ($questions) {
    $ids = array_column($questions, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($ph) ORDER BY question_id,sort_order,id");
    $s->execute($ids);
    foreach ($s->fetchAll() as $o) {
        $options[(int)$o['question_id']][] = $o;
    }
}

function media_label(?string $value): string
{
    return match ($value) {
        'question_image' => 'Bild zur Frage empfohlen',
        'answer_images' => 'Bilder als Antworten empfohlen',
        default => 'Kein Bild empfohlen',
    };
}

admin_header('Fragen Review', $quiz['title']);
?>
<?php if(isset($_GET['saved'])): ?><div class="alert alert-success">Gespeichert.</div><?php endif; ?>
<?php if(isset($_GET['ai_generated'])): ?><div class="alert alert-info">KI-Fragen wurden als Entwurf gespeichert. Bitte prüfen und veröffentlichen.</div><?php endif; ?>
<?php if(isset($_GET['published'])): ?><div class="alert alert-success">Quiz veröffentlicht.</div><?php endif; ?>
<?php if(isset($_GET['image_generated'])): ?><div class="alert alert-info">KI-Bild wurde generiert und eingetragen. Bitte prüfen.</div><?php endif; ?>

<div class="card-soft p-4 mb-4">
  <div class="d-flex justify-content-between gap-3 flex-wrap">
    <div>
      <span class="badge <?= $quiz['status']==='published'?'text-bg-success':'text-bg-secondary' ?>"><?=admin_h($quiz['status'])?></span>
      <h2 class="h4 fw-bold mt-2"><?=admin_h($quiz['title'])?></h2>
      <p class="text-muted mb-0"><?=admin_h($quiz['description'])?></p>
    </div>
    <div class="d-flex gap-2 align-items-start flex-wrap">
      <a class="btn btn-outline-primary" href="quiz_ai_generate.php?quiz_id=<?=$quizId?>">✨ Fragen generieren</a>
      <a class="btn btn-outline-secondary" href="quiz_visuals.php?quiz_id=<?=$quizId?>">🎨 Visuals</a>
      <a class="btn btn-light" target="_blank" href="../quiz.php?key=<?=admin_h($quiz['quiz_key'])?>">Vorschau</a>
    </div>
  </div>
</div>

<form method="post">
<?php foreach($questions as $q): ?>
  <div class="card-soft p-4 mb-3">
    <div class="d-flex justify-content-between gap-3 flex-wrap">
      <div>
        <span class="badge <?= $q['status']==='published'?'text-bg-success':'text-bg-secondary' ?>"><?=admin_h($q['status'])?></span>
        <?php if((int)$q['ai_generated']===1):?> <span class="badge text-bg-primary">KI</span><?php endif;?>
        <span class="badge text-bg-light text-dark"><?= admin_h(media_label($q['media_recommendation'] ?? 'none')) ?></span>
      </div>
      <small class="text-muted"><?= (int)($q['times_correct']??0)?> richtig / <?= (int)($q['times_wrong']??0)?> falsch · Schwierigkeit <?=admin_h($q['calculated_difficulty']??$q['difficulty_calculated'])?></small>
    </div>

    <label class="form-label fw-bold mt-3">Frage</label>
    <textarea class="form-control mb-3" name="questions[<?=(int)$q['id']?>][question_text]"><?=admin_h($q['question_text'])?></textarea>

    <div class="border rounded p-3 mb-3 bg-light">
      <h6 class="fw-bold">Medien zur Frage</h6>
      <?php if(($q['media_recommendation'] ?? 'none') !== 'none'): ?>
        <div class="alert alert-info py-2">
          <strong>KI-Empfehlung:</strong> <?= admin_h(media_label($q['media_recommendation'])) ?><br>
          <?php if(!empty($q['media_search_terms'])): ?><strong>Suchbegriffe:</strong> <?= admin_h($q['media_search_terms']) ?><br><?php endif; ?>
          <?php if(!empty($q['media_prompt'])): ?><strong>Bildprompt:</strong> <?= admin_h($q['media_prompt']) ?><?php endif; ?>
        </div>
      <?php endif; ?>

      <input type="hidden" name="questions[<?=(int)$q['id']?>][media_recommendation]" value="<?=admin_h($q['media_recommendation'] ?? 'none')?>">
      <input type="hidden" name="questions[<?=(int)$q['id']?>][media_prompt]" value="<?=admin_h($q['media_prompt'] ?? '')?>">
      <input type="hidden" name="questions[<?=(int)$q['id']?>][media_search_terms]" value="<?=admin_h($q['media_search_terms'] ?? '')?>">

      <div class="row g-2">
        <div class="col-md-3">
          <label class="form-label">Typ</label>
          <select class="form-select" name="questions[<?=(int)$q['id']?>][media_type]">
            <option value="none" <?=($q['media_type']??'none')==='none'?'selected':''?>>kein Medium</option>
            <option value="image" <?=($q['media_type']??'none')==='image'?'selected':''?>>Bild</option>
            <option value="audio" <?=($q['media_type']??'none')==='audio'?'selected':''?>>Audio</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Pfad / URL</label>
          <input class="form-control" name="questions[<?=(int)$q['id']?>][media_path]" value="<?=admin_h($q['media_path'] ?? '')?>" placeholder="/uploads/ai/question-images/elster.png">
        </div>
        <div class="col-md-3">
          <label class="form-label">Alt-Text</label>
          <input class="form-control" name="questions[<?=(int)$q['id']?>][media_alt]" value="<?=admin_h($q['media_alt'] ?? '')?>">
        </div>
      </div>

      <div class="d-flex gap-2 mt-3 flex-wrap">
        <?php if(!empty($q['media_prompt'])): ?>
          <button class="btn btn-sm btn-outline-primary" name="action" value="generate_question_image" type="submit" formaction="quiz_questions.php?quiz_id=<?=$quizId?>" onclick="this.form.question_id.value='<?=(int)$q['id']?>'">✨ Frage-Bild generieren</button>
        <?php endif; ?>
        <?php if(!empty($q['media_search_terms'])): ?>
          <a class="btn btn-sm btn-light" target="_blank" href="<?=admin_h(elevaro_freepik_search_url($q['media_search_terms']))?>">Freepik suchen</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label fw-bold">Richtige Antwort</label>
        <input class="form-control" name="questions[<?=(int)$q['id']?>][correct_answer]" value="<?=admin_h($q['correct_answer'])?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-bold">Schwierigkeit</label>
        <input class="form-control" name="questions[<?=(int)$q['id']?>][difficulty_manual]" value="<?=admin_h($q['difficulty_manual']??'')?>">
      </div>
    </div>

    <label class="form-label fw-bold mt-3">Antwortoptionen</label>
    <?php foreach($options[(int)$q['id']]??[] as $o): ?>
      <div class="border rounded p-3 mb-2 bg-white">
        <input class="form-control mb-2" name="questions[<?=(int)$q['id']?>][options][<?=(int)$o['id']?>][option_text]" value="<?=admin_h($o['option_text'])?>">

        <?php if(($q['media_recommendation'] ?? '') === 'answer_images'): ?>
          <div class="alert alert-info py-2">
            <strong>Bild für diese Antwort empfohlen.</strong><br>
            <?php if(!empty($o['media_search_terms'])): ?><strong>Suchbegriffe:</strong> <?=admin_h($o['media_search_terms'])?><br><?php endif; ?>
            <?php if(!empty($o['media_prompt'])): ?><strong>Bildprompt:</strong> <?=admin_h($o['media_prompt'])?><?php endif; ?>
          </div>
        <?php endif; ?>

        <input type="hidden" name="questions[<?=(int)$q['id']?>][options][<?=(int)$o['id']?>][media_prompt]" value="<?=admin_h($o['media_prompt'] ?? '')?>">
        <input type="hidden" name="questions[<?=(int)$q['id']?>][options][<?=(int)$o['id']?>][media_search_terms]" value="<?=admin_h($o['media_search_terms'] ?? '')?>">

        <div class="row g-2">
          <div class="col-md-3">
            <select class="form-select" name="questions[<?=(int)$q['id']?>][options][<?=(int)$o['id']?>][media_type]">
              <option value="none" <?=($o['media_type']??'none')==='none'?'selected':''?>>kein Bild</option>
              <option value="image" <?=($o['media_type']??'none')==='image'?'selected':''?>>Bild</option>
            </select>
          </div>
          <div class="col-md-6">
            <input class="form-control" name="questions[<?=(int)$q['id']?>][options][<?=(int)$o['id']?>][media_path]" value="<?=admin_h($o['media_path'] ?? '')?>" placeholder="/uploads/ai/answer-images/elster.png">
          </div>
          <div class="col-md-3">
            <input class="form-control" name="questions[<?=(int)$q['id']?>][options][<?=(int)$o['id']?>][media_alt]" value="<?=admin_h($o['media_alt'] ?? '')?>" placeholder="Alt-Text">
          </div>
        </div>

        <div class="d-flex gap-2 mt-2 flex-wrap">
          <?php if(!empty($o['media_prompt'])): ?>
            <button class="btn btn-sm btn-outline-primary" name="action" value="generate_option_image" type="submit" formaction="quiz_questions.php?quiz_id=<?=$quizId?>" onclick="this.form.option_id.value='<?=(int)$o['id']?>'">✨ Antwort-Bild generieren</button>
          <?php endif; ?>
          <?php if(!empty($o['media_search_terms'])): ?>
            <a class="btn btn-sm btn-light" target="_blank" href="<?=admin_h(elevaro_freepik_search_url($o['media_search_terms']))?>">Freepik suchen</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <label class="form-label fw-bold mt-2">Erklärung</label>
    <textarea class="form-control mb-3" name="questions[<?=(int)$q['id']?>][explanation]"><?=admin_h($q['explanation'])?></textarea>

    <label class="form-label fw-bold">Status</label>
    <select class="form-select" name="questions[<?=(int)$q['id']?>][status]">
      <option value="draft" <?=$q['status']==='draft'?'selected':''?>>Entwurf</option>
      <option value="published" <?=$q['status']==='published'?'selected':''?>>Veröffentlicht</option>
      <option value="archived" <?=$q['status']==='archived'?'selected':''?>>Archiviert</option>
    </select>
  </div>
<?php endforeach; ?>

<?php if(!$questions): ?>
  <div class="card-soft p-4 text-muted">Noch keine Fragen. Nutze „Fragen generieren“.</div>
<?php endif; ?>

<input type="hidden" name="question_id" value="">
<input type="hidden" name="option_id" value="">

<div class="d-flex gap-2 mt-4">
  <button class="btn btn-primary btn-lg">Speichern</button>
  <button class="btn btn-success btn-lg" name="action" value="publish_quiz" type="submit">Quiz & Entwürfe veröffentlichen</button>
</div>
</form>
<?php admin_footer(); ?>
