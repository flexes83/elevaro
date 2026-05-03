<?php
require_once __DIR__ . '/_layout.php';
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
    header('Location: quizzes.php');
    exit;
}

$config = [];
try {
    $config = elevaro_elevenlabs_config();
} catch (Throwable $e) {
    $config = [];
}

$error = null;
$notice = null;

function quiz_audio_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'quizzes'
          AND COLUMN_NAME = :column
    ");
    $stmt->execute(['column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$requiredColumns = [
    'requires_intro_audio',
    'intro_audio_text',
    'intro_audio_path',
    'intro_audio_voice_id',
    'intro_audio_model_id',
    'intro_audio_status',
    'intro_audio_error',
    'intro_audio_generated_at',
];

$missingColumns = array_values(array_filter($requiredColumns, static function ($column) use ($pdo) {
    return !quiz_audio_column_exists($pdo, $column);
}));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$missingColumns) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_audio_settings') {
            $stmt = $pdo->prepare("
                UPDATE quizzes
                SET requires_intro_audio = :requires_intro_audio,
                    intro_audio_text = :intro_audio_text,
                    intro_audio_voice_id = :intro_audio_voice_id,
                    intro_audio_model_id = :intro_audio_model_id
                WHERE id = :id
            ");
            $stmt->execute([
                'requires_intro_audio' => isset($_POST['requires_intro_audio']) ? 1 : 0,
                'intro_audio_text' => trim($_POST['intro_audio_text'] ?? ''),
                'intro_audio_voice_id' => trim($_POST['intro_audio_voice_id'] ?? '') ?: null,
                'intro_audio_model_id' => trim($_POST['intro_audio_model_id'] ?? '') ?: null,
                'id' => $quizId,
            ]);

            header('Location: quiz_audio.php?quiz_id=' . $quizId . '&saved=1');
            exit;
        }

        if ($action === 'generate_intro_audio') {
            $text = trim($_POST['intro_audio_text'] ?? ($quiz['intro_audio_text'] ?? ''));
            $voiceId = trim($_POST['intro_audio_voice_id'] ?? '') ?: ($quiz['intro_audio_voice_id'] ?? null);
            $modelId = trim($_POST['intro_audio_model_id'] ?? '') ?: ($quiz['intro_audio_model_id'] ?? null);

            $generated = elevaro_generate_intro_audio_file($text, $voiceId, $modelId);

            $stmt = $pdo->prepare("
                UPDATE quizzes
                SET requires_intro_audio = 1,
                    intro_audio_text = :intro_audio_text,
                    intro_audio_path = :intro_audio_path,
                    intro_audio_voice_id = :intro_audio_voice_id,
                    intro_audio_model_id = :intro_audio_model_id,
                    intro_audio_status = 'generated',
                    intro_audio_error = NULL,
                    intro_audio_generated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'intro_audio_text' => $text,
                'intro_audio_path' => $generated['path'],
                'intro_audio_voice_id' => $generated['voice_id'],
                'intro_audio_model_id' => $generated['model_id'],
                'id' => $quizId,
            ]);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO audio_generation_events
                      (quiz_id, provider, voice_id, model_id, characters_used, audio_path, status)
                    VALUES
                      (:quiz_id, 'elevenlabs', :voice_id, :model_id, :characters_used, :audio_path, 'success')
                ");
                $stmt->execute([
                    'quiz_id' => $quizId,
                    'voice_id' => $generated['voice_id'],
                    'model_id' => $generated['model_id'],
                    'characters_used' => $generated['characters_used'],
                    'audio_path' => $generated['path'],
                ]);
            } catch (Throwable $e) {
                // Usage logging must not break generation.
            }

            header('Location: quiz_audio.php?quiz_id=' . $quizId . '&generated=1');
            exit;
        }

        if ($action === 'clear_intro_audio') {
            $pdo->prepare("
                UPDATE quizzes
                SET intro_audio_path = NULL,
                    intro_audio_status = 'none',
                    intro_audio_error = NULL,
                    intro_audio_generated_at = NULL
                WHERE id = :id
            ")->execute(['id' => $quizId]);

            header('Location: quiz_audio.php?quiz_id=' . $quizId . '&cleared=1');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();

        try {
            $stmt = $pdo->prepare("
                UPDATE quizzes
                SET intro_audio_status = 'error',
                    intro_audio_error = :error
                WHERE id = :id
            ");
            $stmt->execute(['error' => $error, 'id' => $quizId]);
        } catch (Throwable $ignore) {
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO audio_generation_events
                  (quiz_id, provider, voice_id, model_id, characters_used, status, error_message)
                VALUES
                  (:quiz_id, 'elevenlabs', :voice_id, :model_id, :characters_used, 'error', :error_message)
            ");
            $text = trim($_POST['intro_audio_text'] ?? '');
            $stmt->execute([
                'quiz_id' => $quizId,
                'voice_id' => trim($_POST['intro_audio_voice_id'] ?? '') ?: null,
                'model_id' => trim($_POST['intro_audio_model_id'] ?? '') ?: null,
                'characters_used' => mb_strlen($text, 'UTF-8'),
                'error_message' => $error,
            ]);
        } catch (Throwable $ignore) {
        }
    }

    $stmt = $pdo->prepare("SELECT q.*, sub.name AS subject_name FROM quizzes q LEFT JOIN subjects sub ON sub.id = q.subject_id WHERE q.id = :id LIMIT 1");
    $stmt->execute(['id' => $quizId]);
    $quiz = $stmt->fetch();
}

if (isset($_GET['saved'])) $notice = 'Audio-Einstellungen gespeichert.';
if (isset($_GET['generated'])) $notice = 'Intro-Audio wurde generiert.';
if (isset($_GET['cleared'])) $notice = 'Intro-Audio wurde entfernt.';

admin_header('Listening-Intro', 'Audio für den Quiz-Einstieg generieren und steuern.');
?>

<div class="d-flex justify-content-between align-items-start mb-4 gap-3 flex-wrap">
  <div>
    <h2 class="h4 fw-bold mb-1"><?= admin_h($quiz['title']) ?></h2>
    <p class="text-muted mb-0"><?= admin_h($quiz['subject_name'] ?? '') ?> · Quiz-ID <?= (int)$quizId ?></p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="quiz_questions.php?quiz_id=<?= (int)$quizId ?>">Fragen</a>
    <a class="btn btn-outline-secondary" href="quizzes.php">Alle Quizze</a>
  </div>
</div>

<?php if ($missingColumns): ?>
  <div class="alert alert-warning">
    <strong>DB-Patch fehlt noch.</strong><br>
    Bitte `database/schema_audio_v8.sql` ausführen. Fehlende Spalten:
    <code><?= admin_h(implode(', ', $missingColumns)) ?></code>
  </div>
<?php endif; ?>

<?php if ($notice): ?>
  <div class="alert alert-success"><?= admin_h($notice) ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= admin_h($error) ?></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card card-soft">
      <div class="card-body p-4">
        <h3 class="h5 fw-bold">Listening-Intro</h3>
        <p class="text-muted">Für Listening-Quizze wird dieser Text vorgelesen. Der Quiz-Start kann erst nach dem Abspielen freigegeben werden.</p>

        <form method="post">
          <input type="hidden" name="action" value="save_audio_settings">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="requiresIntroAudio" name="requires_intro_audio" <?= !empty($quiz['requires_intro_audio']) ? 'checked' : '' ?> <?= $missingColumns ? 'disabled' : '' ?>>
            <label class="form-check-label fw-bold" for="requiresIntroAudio">Listening-Intro aktivieren</label>
          </div>

          <label class="form-label fw-bold">Audio-Text</label>
          <textarea class="form-control mb-3" name="intro_audio_text" rows="8" <?= $missingColumns ? 'disabled' : '' ?> placeholder="z. B. Listen carefully. Emma is visiting her grandmother in London..."><?= admin_h($quiz['intro_audio_text'] ?? '') ?></textarea>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">Voice-ID</label>
              <input class="form-control" name="intro_audio_voice_id" value="<?= admin_h($quiz['intro_audio_voice_id'] ?: ($config['default_voice_id'] ?? '')) ?>" <?= $missingColumns ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Model-ID</label>
              <input class="form-control" name="intro_audio_model_id" value="<?= admin_h($quiz['intro_audio_model_id'] ?: ($config['default_model_id'] ?? 'eleven_multilingual_v2')) ?>" <?= $missingColumns ? 'disabled' : '' ?>>
            </div>
          </div>

          <div class="d-flex gap-2 mt-4 flex-wrap">
            <button class="btn btn-primary" <?= $missingColumns ? 'disabled' : '' ?>>Speichern</button>
            <button class="btn btn-success" name="action" value="generate_intro_audio" <?= $missingColumns ? 'disabled' : '' ?>>Audio generieren</button>
            <?php if (!empty($quiz['intro_audio_path'])): ?>
              <button class="btn btn-outline-danger" name="action" value="clear_intro_audio">Audio entfernen</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <h3 class="h5 fw-bold">Vorschau</h3>
        <?php if (!empty($quiz['intro_audio_path'])): ?>
          <audio controls class="w-100 mt-2" src="<?= admin_h($quiz['intro_audio_path']) ?>"></audio>
          <p class="small text-muted mt-2 mb-0">
            Status: <?= admin_h($quiz['intro_audio_status'] ?? '') ?><br>
            Generiert: <?= admin_h($quiz['intro_audio_generated_at'] ?? '') ?>
          </p>
        <?php else: ?>
          <p class="text-muted mb-0">Noch kein Audio generiert.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body p-4">
        <h3 class="h5 fw-bold">Config</h3>
        <p class="small text-muted mb-2">API-Key wird aus <code>/config/elevenlabs.php</code> gelesen.</p>
        <ul class="small text-muted mb-0">
          <li>Voice: <code><?= admin_h($config['default_voice_id'] ?? '–') ?></code></li>
          <li>Model: <code><?= admin_h($config['default_model_id'] ?? '–') ?></code></li>
          <li>Output: <code><?= admin_h($config['output_format'] ?? '–') ?></code></li>
          <li>Budget: <?= admin_h((string)($config['monthly_character_budget'] ?? '–')) ?> Zeichen/Monat</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php admin_footer(); ?>
