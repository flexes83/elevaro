<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/includes/quality_review.php';

$pdo = admin_db();
$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $questionId = (int)($_POST['question_id'] ?? 0);

    try {
        if ($action === 'approve_question' && $questionId) {
            $pdo->prepare("
                UPDATE questions
                SET moderator_status = 'approved',
                    status = 'published',
                    hidden_reason = NULL
                WHERE id = :id
            ")->execute(['id' => $questionId]);
            $notice = 'Frage freigegeben.';
        }

        if ($action === 'hide_question' && $questionId) {
            $pdo->prepare("
                UPDATE questions
                SET moderator_status = 'needs_review',
                    status = 'hidden',
                    hidden_reason = :reason
                WHERE id = :id
            ")->execute([
                'id' => $questionId,
                'reason' => trim($_POST['moderator_note'] ?? '') ?: 'Vom Moderator ausgeblendet',
            ]);
            $notice = 'Frage ausgeblendet.';
        }

        if ($action === 'reject_question' && $questionId) {
            $pdo->prepare("
                UPDATE questions
                SET moderator_status = 'rejected',
                    status = 'hidden',
                    moderator_note = :note,
                    hidden_reason = 'Abgelehnt'
                WHERE id = :id
            ")->execute([
                'id' => $questionId,
                'note' => trim($_POST['moderator_note'] ?? '') ?: null,
            ]);
            $notice = 'Frage abgelehnt.';
        }

        if ($action === 'resolve_report') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $pdo->prepare("
                UPDATE question_reports
                SET status = 'resolved',
                    reviewed_at = NOW(),
                    reviewed_by = :user_id
                WHERE id = :id
            ")->execute([
                'id' => $reportId,
                'user_id' => auth_user()['id'] ?? null,
            ]);
            $notice = 'Meldung erledigt.';
        }

        if ($action === 'dismiss_report') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $pdo->prepare("
                UPDATE question_reports
                SET status = 'dismissed',
                    reviewed_at = NOW(),
                    reviewed_by = :user_id
                WHERE id = :id
            ")->execute([
                'id' => $reportId,
                'user_id' => auth_user()['id'] ?? null,
            ]);
            $notice = 'Meldung verworfen.';
        }

        if ($action === 'validate_question' && $questionId) {
            elevaro_validate_question_with_ai($pdo, $questionId);
            $notice = 'KI-Prüfung durchgeführt.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$qualityReady = elevaro_quality_ready();

$items = [];
if ($qualityReady) {
    $stmt = $pdo->query("
        SELECT
          q.*,
          quiz.title AS quiz_title,
          quiz.quiz_key,
          sub.name AS subject_name,
          COUNT(r.id) AS open_reports
        FROM questions q
        JOIN quizzes quiz ON quiz.id = q.quiz_id
        LEFT JOIN subjects sub ON sub.id = quiz.subject_id
        LEFT JOIN question_reports r ON r.question_id = q.id AND r.status IN ('open','reviewing')
        WHERE
          q.ai_validation_status IN ('needs_review','invalid','error')
          OR q.moderator_status IN ('needs_review','rejected')
          OR q.status IN ('flagged','hidden')
          OR q.reports_count > 0
        GROUP BY q.id, quiz.id, sub.id
        ORDER BY q.reports_count DESC, q.ai_validation_checked_at DESC, q.id DESC
        LIMIT 100
    ");
    $items = $stmt->fetchAll();
}

$reports = [];
if ($qualityReady) {
    $stmt = $pdo->query("
        SELECT
          r.*,
          q.question_text,
          quiz.title AS quiz_title
        FROM question_reports r
        JOIN questions q ON q.id = r.question_id
        JOIN quizzes quiz ON quiz.id = r.quiz_id
        WHERE r.status IN ('open','reviewing')
        ORDER BY r.created_at DESC
        LIMIT 100
    ");
    $reports = $stmt->fetchAll();
}

admin_header('Qualitätsprüfung', 'KI-Validierung, Moderator-Review und gemeldete Fehler.');
?>

<?php if (!$qualityReady): ?>
  <div class="alert alert-warning">
    Bitte zuerst <code>database/schema_quality_v9.sql</code> ausführen.
  </div>
<?php endif; ?>

<?php if ($notice): ?><div class="alert alert-success"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= admin_h($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-soft">
      <div class="card-body p-4">
        <h2 class="h4 fw-bold">Review Queue</h2>
        <p class="text-muted">Fragen mit niedriger KI-Confidence, Meldungen oder manuellem Prüfbedarf.</p>

        <?php if (!$items): ?>
          <div class="alert alert-light mb-0">Keine offenen Fragen in der Review Queue.</div>
        <?php endif; ?>

        <?php foreach ($items as $q): ?>
          <div class="border rounded p-3 mb-3">
            <div class="d-flex justify-content-between gap-2 flex-wrap">
              <div>
                <strong><?= admin_h($q['quiz_title']) ?></strong>
                <div class="small text-muted"><?= admin_h($q['subject_name'] ?? '') ?> · Frage #<?= (int)$q['id'] ?></div>
              </div>
              <div class="d-flex gap-1 flex-wrap">
                <span class="badge text-bg-secondary"><?= admin_h($q['status']) ?></span>
                <span class="badge text-bg-info"><?= admin_h($q['ai_validation_status']) ?></span>
                <span class="badge text-bg-warning"><?= (int)$q['reports_count'] ?> Meldungen</span>
              </div>
            </div>

            <p class="mt-3 mb-2 fw-bold"><?= admin_h($q['question_text']) ?></p>
            <p class="small text-muted mb-2"><strong>Antwort:</strong> <?= admin_h($q['correct_answer']) ?></p>
            <p class="small mb-2"><?= nl2br(admin_h($q['explanation'])) ?></p>

            <?php if (!empty($q['ai_validation_issues']) || !empty($q['ai_validation_suggestion'])): ?>
              <div class="alert alert-warning small">
                <?php if (!empty($q['ai_validation_confidence'])): ?>
                  <strong>Confidence:</strong> <?= admin_h((string)$q['ai_validation_confidence']) ?><br>
                <?php endif; ?>
                <?php if (!empty($q['ai_validation_issues'])): ?>
                  <strong>Issues:</strong><br><?= nl2br(admin_h($q['ai_validation_issues'])) ?><br>
                <?php endif; ?>
                <?php if (!empty($q['ai_validation_suggestion'])): ?>
                  <strong>Vorschlag:</strong><br><?= nl2br(admin_h($q['ai_validation_suggestion'])) ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <form method="post" class="d-flex gap-2 flex-wrap align-items-center">
              <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
              <input class="form-control form-control-sm" style="max-width:280px" name="moderator_note" placeholder="Moderator-Notiz optional">
              <button class="btn btn-sm btn-success" name="action" value="approve_question">Freigeben</button>
              <button class="btn btn-sm btn-outline-primary" name="action" value="validate_question">KI erneut prüfen</button>
              <button class="btn btn-sm btn-outline-warning" name="action" value="hide_question">Ausblenden</button>
              <button class="btn btn-sm btn-outline-danger" name="action" value="reject_question">Ablehnen</button>
              <a class="btn btn-sm btn-light" href="quiz_questions.php?quiz_id=<?= (int)$q['quiz_id'] ?>">Bearbeiten</a>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card card-soft">
      <div class="card-body p-4">
        <h2 class="h4 fw-bold">Offene Meldungen</h2>
        <?php if (!$reports): ?>
          <div class="alert alert-light mb-0">Keine offenen Meldungen.</div>
        <?php endif; ?>

        <?php foreach ($reports as $r): ?>
          <div class="border rounded p-3 mb-3">
            <strong><?= admin_h($r['quiz_title']) ?></strong>
            <p class="small text-muted mb-2"><?= admin_h($r['created_at']) ?> · <?= admin_h($r['reason']) ?></p>
            <p class="mb-2"><?= admin_h($r['question_text']) ?></p>
            <?php if (!empty($r['message'])): ?>
              <div class="alert alert-light small"><?= nl2br(admin_h($r['message'])) ?></div>
            <?php endif; ?>
            <form method="post" class="d-flex gap-2 flex-wrap">
              <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-success" name="action" value="resolve_report">Erledigt</button>
              <button class="btn btn-sm btn-outline-secondary" name="action" value="dismiss_report">Verwerfen</button>
              <a class="btn btn-sm btn-light" href="quiz_questions.php?quiz_id=<?= (int)$r['quiz_id'] ?>">Frage öffnen</a>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php admin_footer(); ?>
