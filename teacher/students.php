<?php
require_once __DIR__ . '/_layout.php';
$class = teacher_selected_class();
if (!$class) { teacher_header('Schüler', 'Lege zuerst eine Klasse an.'); echo '<div class="card card-soft"><div class="card-body p-4"><a class="btn btn-primary" href="classes.php">Klasse anlegen</a></div></div>'; teacher_footer(); exit; }
$stmt = teacher_db()->prepare("SELECT u.id, u.display_name, u.email, ccu.created_at FROM class_code_users ccu JOIN class_codes cc ON cc.id = ccu.class_code_id JOIN auth_users u ON u.id = ccu.user_id WHERE cc.class_id = :class_id ORDER BY ccu.created_at DESC");
$stmt->execute(['class_id' => (int)$class['id']]);
$students = $stmt->fetchAll();
teacher_header('Schüler', 'Schüler der Klasse ' . teacher_class_label($class) . '.');
?>
<div class="card card-soft admin-table-card"><div class="card-body p-4">
  <div class="d-flex justify-content-between gap-3 align-items-center mb-3"><h2 class="h5 fw-bold mb-0">Angemeldete Schüler</h2><a class="btn btn-primary" href="settings.php?class_id=<?= (int)$class['id'] ?>">QR-Code teilen</a></div>
  <table class="table"><thead><tr><th>Name</th><th>E-Mail</th><th>Beigetreten</th></tr></thead><tbody>
    <?php foreach ($students as $student): ?><tr><td><strong><?= teacher_h($student['display_name'] ?: 'Schüler #' . $student['id']) ?></strong></td><td><?= teacher_h($student['email']) ?></td><td><?= teacher_h($student['created_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$students): ?><tr><td colspan="3" class="text-muted">Noch keine Schüler beigetreten. Teile den Klassencode oder QR-Link.</td></tr><?php endif; ?>
  </tbody></table>
</div></div>
<?php teacher_footer(); ?>
