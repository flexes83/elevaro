<?php
require_once __DIR__ . '/_layout.php';
$class = teacher_selected_class();
if (!$class) { teacher_header('Schüler', 'Lege zuerst eine Klasse an.'); echo '<div class="card card-soft"><div class="card-body p-4"><a class="btn btn-primary" href="classes.php">Klasse anlegen</a></div></div>'; teacher_footer(); exit; }
$classId = (int)$class['id'];
$registered = [];
if (teacher_table_exists('teacher_class_students')) {
    $stmt = teacher_db()->prepare("SELECT u.id, u.display_name, u.email, tcs.created_at AS created_at FROM teacher_class_students tcs JOIN auth_users u ON u.id = tcs.user_id WHERE tcs.class_id = :class_id ORDER BY tcs.created_at DESC");
    $stmt->execute(['class_id' => $classId]);
    $registered = $stmt->fetchAll();
}
$participants = [];
if (teacher_table_exists('classroom_participants')) {
    $stmt = teacher_db()->prepare("SELECT * FROM classroom_participants WHERE class_id = :class_id ORDER BY last_seen_at DESC, created_at DESC");
    $stmt->execute(['class_id' => $classId]);
    $participants = $stmt->fetchAll();
}
teacher_header('Schüler', 'Schüler und Gast-Teilnahmen der Klasse ' . teacher_class_label($class) . '.');
?>
<div class="card card-soft admin-table-card"><div class="card-body p-4">
  <div class="d-flex justify-content-between gap-3 align-items-center mb-3"><h2 class="h5 fw-bold mb-0">Klassenraum-Teilnehmer</h2><a class="btn btn-primary" href="settings.php?class_id=<?= $classId ?>">QR-Code teilen</a></div>
  <table class="table"><thead><tr><th>Name</th><th>Typ</th><th>Status</th><th>Zuletzt gesehen</th></tr></thead><tbody>
    <?php foreach ($participants as $student): $online = !empty($student['last_seen_at']) && strtotime((string)$student['last_seen_at']) >= time() - 180; ?>
      <tr><td><strong><?= teacher_h(($student['avatar_emoji'] ?? '🙂') . ' ' . $student['display_name']) ?></strong></td><td><?= !empty($student['user_id']) ? 'Schülerkonto' : 'Gast' ?></td><td><?= $online ? '<span class="badge text-bg-success">online</span>' : '<span class="badge text-bg-light">offline</span>' ?></td><td><?= teacher_h($student['last_seen_at'] ?: $student['created_at']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$participants): ?><tr><td colspan="4" class="text-muted">Noch keine Schüler im Klassenraum. Teile den QR-Code oder Link.</td></tr><?php endif; ?>
  </tbody></table>
</div></div>

<?php if ($registered): ?>
<div class="card card-soft admin-table-card mt-4"><div class="card-body p-4">
  <h2 class="h5 fw-bold mb-3">Dauerhaft verknüpfte Schülerkonten</h2>
  <table class="table"><thead><tr><th>Name</th><th>E-Mail</th><th>Beigetreten</th></tr></thead><tbody>
    <?php foreach ($registered as $student): ?><tr><td><strong><?= teacher_h($student['display_name'] ?: 'Schüler #' . $student['id']) ?></strong></td><td><?= teacher_h($student['email']) ?></td><td><?= teacher_h($student['created_at']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div></div>
<?php endif; ?>
<?php teacher_footer(); ?>
