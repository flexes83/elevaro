<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/library_units.php';

teacher_library_ensure_share_schema();
$token = trim((string)($_GET['token'] ?? ''));
$share = null;
$unit = null;
$items = [];
$error = '';

if ($token === '') {
    $error = 'Dieser Freigabelink ist ungültig.';
} else {
    $stmt = teacher_db()->prepare('SELECT * FROM teacher_unit_colleague_shares WHERE token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $share = $stmt->fetch();
    if (!$share) {
        $error = 'Diese Freigabe wurde nicht gefunden.';
    } else {
        $stmt = teacher_db()->prepare('SELECT * FROM teacher_units WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int)$share['unit_id']]);
        $unit = $stmt->fetch();
        $refs = json_decode((string)($share['item_refs_json'] ?? '[]'), true);
        $refs = is_array($refs) ? $refs : [];
        foreach ($refs as $ref) {
            $parsed = teacher_library_parse_item_ref((string)$ref);
            if (!$parsed) continue;
            if ($parsed['type'] === 'worksheet') {
                $q = teacher_db()->prepare('SELECT id, title, description FROM teacher_custom_quizzes WHERE id = :id LIMIT 1');
                $q->execute(['id' => $parsed['id']]);
                if ($row = $q->fetch()) {
                    $items[] = ['type' => 'worksheet', 'title' => $row['title'], 'description' => $row['description'], 'url' => '/teacher/material_pdf.php?custom_quiz_id=' . (int)$row['id']];
                }
            } else {
                $q = teacher_db()->prepare('SELECT id, quiz_key, title, description, listening_mode FROM quizzes WHERE id = :id LIMIT 1');
                $q->execute(['id' => $parsed['id']]);
                if ($row = $q->fetch()) {
                    $type = ((int)($row['listening_mode'] ?? 0) === 1) ? 'listening' : 'quiz';
                    $items[] = ['type' => $type, 'title' => $row['title'], 'description' => $row['description'], 'url' => !empty($row['quiz_key']) ? '/quiz.php?key=' . urlencode((string)$row['quiz_key']) : ''];
                }
            }
        }
    }
}

teacher_header('Geteilte Unit', 'Vorschau auf geteilte Elevaro-Inhalte.');
?>
<style>
.shared-unit-card{max-width:900px;margin:0 auto;padding:28px;border-radius:32px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 24px 70px rgba(23,32,51,.08)}
.shared-unit-kicker{font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#5a4ff3;font-size:.78rem}.shared-unit-card h2{font-weight:950;color:#172033;margin:8px 0 8px}.shared-unit-meta{color:#64748b;font-weight:750}.shared-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;margin-top:12px;padding:14px;border-radius:20px;background:#f8fafc;border:1px solid rgba(23,32,51,.07)}.shared-icon{width:42px;height:42px;border-radius:15px;display:flex;align-items:center;justify-content:center;background:#fff;box-shadow:0 8px 20px rgba(23,32,51,.06)}.shared-item strong{display:block}.shared-item small{display:block;color:#64748b;font-weight:750}.shared-item .btn{border-radius:999px;font-weight:850}
</style>
<div class="shared-unit-card">
<?php if ($error): ?>
  <div class="alert alert-danger rounded-4 mb-0"><?= teacher_h($error) ?></div>
<?php else: ?>
  <div class="shared-unit-kicker">Elevaro-Unit geteilt</div>
  <h2><?= teacher_h((string)($unit['title'] ?? 'Unit')) ?></h2>
  <div class="shared-unit-meta">
    <?= teacher_h((string)($unit['subject_label'] ?? '')) ?><?= !empty($unit['grade']) ? ' · Klasse ' . teacher_h((string)$unit['grade']) : '' ?><?= !empty($unit['curriculum_subtopic_label'] ?: $unit['curriculum_topic_label']) ? ' · ' . teacher_h((string)(($unit['curriculum_subtopic_label'] ?? '') ?: ($unit['curriculum_topic_label'] ?? ''))) : '' ?>
  </div>
  <hr class="my-4">
  <?php if (!$items): ?>
    <p class="text-muted mb-0">Keine Inhalte in dieser Freigabe gefunden.</p>
  <?php else: ?>
    <?php foreach ($items as $item): ?>
      <div class="shared-item">
        <span class="shared-icon"><?= teacher_library_type_icon((string)$item['type']) ?></span>
        <span><strong><?= teacher_h((string)$item['title']) ?></strong><small><?= teacher_h(teacher_library_type_label((string)$item['type'])) ?></small></span>
        <?php if (!empty($item['url'])): ?><a class="btn btn-primary btn-sm" href="<?= teacher_h((string)$item['url']) ?>">Öffnen</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php teacher_footer(); ?>
