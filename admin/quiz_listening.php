<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/includes/listening_comprehension_ai.php';
require_once __DIR__ . '/../app/includes/elevenlabs_client.php';

$pdo = admin_db();
$quizId = (int)($_GET['quiz_id'] ?? 0);

if (!$quizId) {
    header('Location: quizzes.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT q.*, sub.name AS subject_name
    FROM quizzes q
    LEFT JOIN subjects sub ON sub.id = q.subject_id
    WHERE q.id = :id
    LIMIT 1
");
$stmt->execute(['id' => $quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    admin_header('Quiz nicht gefunden');
    echo '<div class="alert alert-danger">Quiz nicht gefunden.</div>';
    admin_footer();
    exit;
}

function lc_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$missing = [];
foreach ([
    'listening_mode',
    'listening_text',
    'listening_audio_path',
    'listening_summary',
    'listening_prompt',
    'listening_voice_id',
    'listening_model_id',
    'listening_status',
    'listening_error',
    'listening_generated_at',
] as $col) {
    if (!lc_column_exists($pdo, 'quizzes', $col)) $missing[] = 'quizzes.' . $col;
}
foreach (['source_context','source_excerpt'] as $col) {
    if (!lc_column_exists($pdo, 'questions', $col)) $missing[] = 'questions.' . $col;
}

$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$missing) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_listening_settings') {
            $pdo->prepare("
                UPDATE quizzes
                SET listening_mode = :listening_mode,
                    listening_text = :listening_text,
                    listening_summary = :listening_summary,
                    listening_voice_id = :listening_voice_id,
                    listening_model_id = :listening_model_id,
                    listening_status = CASE
                      WHEN :listening_text_2 <> '' THEN 'text_generated'
                      ELSE 'none'
                    END
                WHERE id = :id
            ")->execute([
                'listening_mode' => isset($_POST['listening_mode']) ? 1 : 0,
                'listening_text' => trim($_POST['listening_text'] ?? ''),
                'listening_summary' => trim($_POST['listening_summary'] ?? ''),
                'listening_voice_id' => trim($_POST['listening_voice_id'] ?? '') ?: null,
                'listening_model_id' => trim($_POST['listening_model_id'] ?? '') ?: null,
                'listening_text_2' => trim($_POST['listening_text'] ?? ''),
                'id' => $quizId,
            ]);

            header('Location: quiz_listening.php?quiz_id=' . $quizId . '&saved=1');
            exit;
        }

        if ($action === 'generate_text_and_questions') {
            $questionCount = (int)($_POST['question_count'] ?? 12);
            $generated = elevaro_generate_listening_comprehension($quiz, $questionCount);

            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE quizzes
                SET listening_mode = 1,
                    listening_text = :listening_text,
                    listening_summary = :summary,
                    listening_prompt = :prompt,
                    listening_status = 'text_generated',
                    listening_error = NULL,
                    listening_generated_at = NOW()
                WHERE id = :id
            ")->execute([
                'listening_text' => $generated['json']['listening_text'],
                'summary' => $generated['json']['summary'],
                'prompt' => $generated['prompt'],
                'id' => $quizId,
            ]);

            elevaro_insert_listening_questions($pdo, $quizId, $generated['json']['questions']);

            $pdo->commit();

            header('Location: quiz_listening.php?quiz_id=' . $quizId . '&generated=1');
            exit;
        }

        if ($action === 'generate_audio') {
            $text = trim($_POST['listening_text'] ?? ($quiz['listening_text'] ?? ''));

            if ($text === '') {
                throw new RuntimeException('Kein Listening-Text vorhanden.');
            }

            $voiceId = trim($_POST['listening_voice_id'] ?? '') ?: ($quiz['listening_voice_id'] ?? null) ?: elevaro_resolve_voice_for_listening_comprehension($quiz);
            $modelId = trim($_POST['listening_model_id'] ?? '') ?: ($quiz['listening_model_id'] ?? null);

            $audio = elevaro_generate_audio_file($text, 'listening_quiz_' . $quizId, $voiceId, $modelId);

            $pdo->prepare("
                UPDATE quizzes
                SET listening_mode = 1,
                    listening_text = :listening_text,
                    listening_audio_path = :audio_path,
                    listening_voice_id = :voice_id,
                    listening_model_id = :model_id,
                    listening_status = 'audio_generated',
                    listening_error = NULL
                WHERE id = :id
            ")->execute([
                'listening_text' => $text,
                'audio_path' => $audio['path'],
                'voice_id' => $audio['voice_id'],
                'model_id' => $audio['model_id'],
                'id' => $quizId,
            ]);

            try {
                $pdo->prepare("
                    INSERT INTO audio_generation_events
                      (quiz_id, provider, voice_id, model_id, characters_used, audio_path, status)
                    VALUES
                      (:quiz_id, 'elevenlabs', :voice_id, :model_id, :characters_used, :audio_path, 'success')
                ")->execute([
                    'quiz_id' => $quizId,
                    'voice_id' => $audio['voice_id'],
                    'model_id' => $audio['model_id'],
                    'characters_used' => $audio['characters_used'],
                    'audio_path' => $audio['path'],
                ]);
            } catch (Throwable $ignore) {}

            header('Location: quiz_listening.php?quiz_id=' . $quizId . '&audio=1');
            exit;
        }

        if ($action === 'disable_listening') {
            $pdo->prepare("UPDATE quizzes SET listening_mode = 0 WHERE id = :id")->execute(['id' => $quizId]);
            header('Location: quiz_listening.php?quiz_id=' . $quizId . '&disabled=1');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();

        try {
            $pdo->prepare("
                UPDATE quizzes
                SET listening_status = 'error',
                    listening_error = :error
                WHERE id = :id
            ")->execute(['error' => $error, 'id' => $quizId]);
        } catch (Throwable $ignore) {}
    }

    $stmt->execute(['id' => $quizId]);
    $quiz = $stmt->fetch();
}

if (isset($_GET['saved'])) $notice = 'Listening-Einstellungen gespeichert.';
if (isset($_GET['generated'])) $notice = 'Listening-Text und passende Fragen wurden generiert.';
if (isset($_GET['audio'])) $notice = 'Audio wurde generiert.';
if (isset($_GET['disabled'])) $notice = 'Listening-Modus deaktiviert.';

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM questions
    WHERE quiz_id = :quiz_id
      AND source_context = 'listening_text'
");
$stmt->execute(['quiz_id' => $quizId]);
$listeningQuestionCount = (int)$stmt->fetchColumn();

$config = [];
try { $config = elevaro_elevenlabs_config(); } catch (Throwable $e) {}

admin_header('Listening-Comprehension', '3–4 Minuten Hörtext + darauf abgestimmte Fragen generieren.');
?>

<div class="d-flex justify-content-between gap-3 flex-wrap align-items-start mb-4">
  <div>
    <h2 class="h4 fw-bold mb-1"><?= admin_h($quiz['title']) ?></h2>
    <p class="text-muted mb-0"><?= admin_h($quiz['subject_name'] ?? '') ?> · Quiz-ID <?= (int)$quizId ?> · <?= $listeningQuestionCount ?> Listening-Fragen</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-secondary" href="quiz_questions.php?quiz_id=<?= (int)$quizId ?>">Fragen</a>
    <a class="btn btn-light" target="_blank" href="../quiz.php?key=<?= admin_h($quiz['quiz_key']) ?>">Vorschau</a>
  </div>
</div>

<?php if ($missing): ?>
  <div class="alert alert-warning">
    <strong>DB-Patch fehlt.</strong><br>
    Bitte `database/schema_listening_comprehension_v83.sql` ausführen.<br>
    Fehlend: <code><?= admin_h(implode(', ', $missing)) ?></code>
  </div>
<?php endif; ?>

<?php if ($notice): ?><div class="alert alert-success"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= admin_h($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <h3 class="h5 fw-bold">KI-Generator</h3>
        <p class="text-muted">Erzeugt einen 3–4-minütigen Infotext und ersetzt die bisherigen Listening-Fragen dieses Quiz.</p>

        <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
          <input type="hidden" name="action" value="generate_text_and_questions">
          <div>
            <label class="form-label fw-bold">Anzahl Fragen</label>
            <input class="form-control" type="number" min="6" max="20" name="question_count" value="12" <?= $missing ? 'disabled' : '' ?>>
          </div>
          <button class="btn btn-primary" <?= $missing ? 'disabled' : '' ?>>🎧 Listening-Quiz generieren</button>
        </form>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body p-4">
        <h3 class="h5 fw-bold">Listening-Text</h3>

        <form method="post">
          <input type="hidden" name="action" value="save_listening_settings">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="listening_mode" id="listeningMode" <?= !empty($quiz['listening_mode']) ? 'checked' : '' ?> <?= $missing ? 'disabled' : '' ?>>
            <label class="form-check-label fw-bold" for="listeningMode">Listening-Comprehension-Modus aktivieren</label>
          </div>

          <label class="form-label fw-bold">Zusammenfassung / interner Hinweis</label>
          <textarea class="form-control mb-3" name="listening_summary" rows="2" <?= $missing ? 'disabled' : '' ?>><?= admin_h($quiz['listening_summary'] ?? '') ?></textarea>

          <label class="form-label fw-bold">Hörtext</label>
          <textarea class="form-control mb-3" name="listening_text" rows="16" <?= $missing ? 'disabled' : '' ?>><?= admin_h($quiz['listening_text'] ?? '') ?></textarea>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">Voice-ID</label>
              <input class="form-control" name="listening_voice_id" value="<?= admin_h($quiz['listening_voice_id'] ?: ($config['english_voice_id'] ?? $config['german_soft_voice_id'] ?? $config['default_voice_id'] ?? '')) ?>" <?= $missing ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Model-ID</label>
              <input class="form-control" name="listening_model_id" value="<?= admin_h($quiz['listening_model_id'] ?: ($config['default_model_id'] ?? 'eleven_multilingual_v2')) ?>" <?= $missing ? 'disabled' : '' ?>>
            </div>
          </div>

          <div class="d-flex gap-2 mt-4 flex-wrap">
            <button class="btn btn-primary" <?= $missing ? 'disabled' : '' ?>>Speichern</button>
            <button class="btn btn-success" name="action" value="generate_audio" <?= $missing ? 'disabled' : '' ?>>🔊 Audio erzeugen</button>
            <button class="btn btn-outline-danger" name="action" value="disable_listening" <?= $missing ? 'disabled' : '' ?>>Modus deaktivieren</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <h3 class="h5 fw-bold">Audio</h3>
        <?php if (!empty($quiz['listening_audio_path'])): ?>
          <audio class="w-100" controls src="<?= admin_h($quiz['listening_audio_path']) ?>"></audio>
          <p class="small text-muted mt-2 mb-0">
            Status: <?= admin_h($quiz['listening_status'] ?? '') ?><br>
            Voice: <code><?= admin_h($quiz['listening_voice_id'] ?? '') ?></code>
          </p>
        <?php else: ?>
          <p class="text-muted mb-0">Noch kein Audio generiert.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body p-4">
        <h3 class="h5 fw-bold">Ablauf im Frontend</h3>
        <ol class="small text-muted mb-0">
          <li>Schüler hören den 3–4-Minuten-Text.</li>
          <li>Der Startbutton bleibt gesperrt.</li>
          <li>Nach vollständigem Abspielen werden die Fragen freigegeben.</li>
          <li>Die Fragen stammen aus genau diesem Text.</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<?php admin_footer(); ?>
