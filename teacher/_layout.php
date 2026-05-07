<?php
require_once __DIR__ . '/_bootstrap.php';

function teacher_header(string $title, string $subtitle = ''): void {
    $user = auth_user();
    $classes = teacher_classes();
    $selected = teacher_selected_class();
    $classId = $selected ? (int)$selected['id'] : 0;
    $current = basename($_SERVER['SCRIPT_NAME']);
    $withClass = static function (string $file) use ($classId): string {
        return $classId ? $file . '?class_id=' . $classId : $file;
    };
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= teacher_h($title) ?> – Elevaro Lehrer</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/admin/assets/admin.css" rel="stylesheet">
  <style>
    .teacher-class-switch{margin:0 0 18px;padding:16px;border-radius:22px;background:linear-gradient(135deg,rgba(90,79,243,.12),rgba(139,124,255,.08));border:1px solid rgba(90,79,243,.18)}
    .teacher-current-class-label{display:flex;align-items:center;gap:8px;margin:0 0 10px;font-size:.78rem;font-weight:900;color:#5a4ff3;text-transform:uppercase;letter-spacing:.04em}
    .teacher-current-class-name{display:block;font-weight:950;color:#172033;line-height:1.15;margin-bottom:12px}
    .teacher-class-switch label{font-size:.78rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .invite-code{font-size:1.35rem;letter-spacing:.12em;font-weight:900;background:#172033;color:#fff;border-radius:16px;padding:12px 16px;display:inline-block}
    .qr-placeholder{width:180px;height:180px;border-radius:24px;background:repeating-linear-gradient(45deg,#172033 0 8px,#fff 8px 16px);box-shadow:inset 0 0 0 16px #fff;border:1px solid rgba(23,32,51,.12)}
  </style>
</head>
<body>
<div class="admin-layout">
  <aside class="admin-sidebar">
    <a class="admin-brand" href="/teacher/index.php">Elevaro</a>

    <div class="teacher-class-switch">
      <span class="teacher-current-class-label">🏫 Aktuelle Klasse</span>
      <?php if ($selected): ?>
        <span class="teacher-current-class-name"><?= teacher_h(teacher_class_label($selected)) ?></span>
      <?php endif; ?>
      <label class="form-label mb-1">Klasse wechseln</label>
      <?php if ($classes): ?>
        <form method="get" action="<?= teacher_h($current) ?>">
          <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($classes as $class): ?>
              <option value="<?= (int)$class['id'] ?>" <?= $classId === (int)$class['id'] ? 'selected' : '' ?>><?= teacher_h(teacher_class_label($class)) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php else: ?>
        <a class="btn btn-sm btn-primary w-100" href="classes.php">Erste Klasse anlegen</a>
      <?php endif; ?>
    </div>

    <nav class="admin-nav">
      <a class="<?= teacher_active('index.php') ?>" href="<?= teacher_h($withClass('index.php')) ?>">🏠 Dashboard</a>
      <a class="<?= teacher_active('students.php') ?>" href="<?= teacher_h($withClass('students.php')) ?>">👧 Schüler</a>
      <a class="<?= teacher_active('quizzes.php') ?>" href="<?= teacher_h($withClass('quizzes.php')) ?>">📝 Quizzes</a>
      <a class="<?= teacher_active('ai_wizard.php') ?>" href="<?= teacher_h($withClass('ai_wizard.php')) ?>">✨ KI-Wizard</a>
      <a class="<?= teacher_active('live.php') ?>" href="<?= teacher_h($withClass('live.php')) ?>">⚡ Live Quizz</a>
      <a class="<?= teacher_active('settings.php') ?>" href="<?= teacher_h($withClass('settings.php')) ?>">⚙️ Einstellungen</a>
      <a class="<?= teacher_active('classes.php') ?>" href="classes.php">🏫 Klassen</a>
    </nav>

    <div class="admin-role-box mt-4">
      <div class="admin-role-user">
        <strong><?= teacher_h($user['display_name'] ?: $user['username'] ?: $user['email']) ?></strong>
        <span><?= teacher_h(auth_role_label((string)auth_effective_role())) ?> · kostenpflichtig</span>
      </div>
      <a class="admin-logout" href="/logout.php">Logout</a>
    </div>


  </aside>

  <main class="admin-main">
    <header class="admin-page-head">
      <div>
        <h1><?= teacher_h($title) ?></h1>
        <?php if ($subtitle): ?><p><?= teacher_h($subtitle) ?></p><?php endif; ?>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($classId): ?><a class="btn btn-outline-primary" href="/classroom.php?class_id=<?= (int)$classId ?>">🚪 Klassenraum betreten</a><?php endif; ?>
        <a class="btn btn-primary" href="classes.php">🏫 Klassen verwalten</a>
      </div>
    </header>
<?php }

function teacher_footer(): void { ?>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php } ?>
