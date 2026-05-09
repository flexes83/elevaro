<?php
require_once __DIR__ . '/_bootstrap.php';

function teacher_user_display_name(?array $user = null): string
{
    $user = $user ?: auth_user();
    return trim((string)(($user['display_name'] ?? '') ?: ($user['username'] ?? '') ?: ($user['email'] ?? 'Lehrkraft')));
}

function teacher_user_initials(?array $user = null): string
{
    $name = teacher_user_display_name($user);
    if ($name === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $first = mb_substr($parts[0] ?? '?', 0, 1, 'UTF-8');
    $second = count($parts) > 1 ? mb_substr(end($parts), 0, 1, 'UTF-8') : '';

    return mb_strtoupper($first . $second, 'UTF-8');
}

function teacher_header(string $title, string $subtitle = '', array $options = []): void {
    $user = auth_user();
    $classes = teacher_classes();
    $selected = teacher_selected_class();
    $classId = $selected ? (int)$selected['id'] : 0;
    $current = basename($_SERVER['SCRIPT_NAME']);
    $displayName = teacher_user_display_name($user);
    $initials = teacher_user_initials($user);
    $isGlobalPage = (bool)($options['global_page'] ?? false);
    $isGlobalLibraryPage = $isGlobalPage || $current === 'materials.php';
    $showClassSidebar = !$isGlobalLibraryPage;
    $roleLabel = auth_role_label((string)auth_effective_role());
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
    .teacher-top-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .teacher-user-dropdown{position:relative}
    .teacher-user-button{display:flex;align-items:center;gap:10px;border:1px solid rgba(23,32,51,.10);background:rgba(255,255,255,.86);box-shadow:0 12px 30px rgba(23,32,51,.08);border-radius:999px;padding:7px 10px 7px 7px;color:#172033;font-weight:850;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
    .teacher-user-button:hover,.teacher-user-button:focus{transform:translateY(-1px);box-shadow:0 16px 38px rgba(23,32,51,.12);border-color:rgba(90,79,243,.25)}
    .teacher-user-avatar{width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#5a4ff3,#8b7cff);color:#fff;font-size:.82rem;font-weight:950;letter-spacing:-.03em;box-shadow:inset 0 0 0 1px rgba(255,255,255,.28)}
    .teacher-user-meta{display:flex;flex-direction:column;align-items:flex-start;line-height:1.05;max-width:190px}
    .teacher-user-meta strong{font-size:.9rem;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .teacher-user-meta span{font-size:.72rem;color:#6c7482;font-weight:750;margin-top:3px}
    .teacher-user-menu{border:0;border-radius:22px;padding:8px;min-width:260px;box-shadow:0 24px 60px rgba(23,32,51,.16)}
    .teacher-user-menu .dropdown-header{padding:12px 12px 10px;color:#172033}
    .teacher-user-menu .dropdown-header strong{display:block;font-size:.95rem;line-height:1.2}
    .teacher-user-menu .dropdown-header span{display:block;margin-top:2px;color:#6c7482;font-size:.78rem;font-weight:750}
    .teacher-user-menu .dropdown-item{border-radius:14px;padding:10px 12px;font-weight:800;color:#172033;display:flex;align-items:center;gap:10px}
    .teacher-user-menu .dropdown-item:hover{background:#f3f1ff;color:#4037c9}
    .teacher-user-menu .dropdown-divider{margin:8px 4px}
    .teacher-sidebar-user{display:flex;align-items:center;gap:10px}
    .teacher-sidebar-user .teacher-user-avatar{width:34px;height:34px;font-size:.78rem;box-shadow:none}
    .teacher-sidebar-user strong{display:block;line-height:1.15}
    .teacher-sidebar-user span{display:block;color:rgba(255,255,255,.72);font-size:.76rem;font-weight:750;margin-top:2px}
    .teacher-class-switch{margin:0 0 18px;padding:16px;border-radius:22px;background:linear-gradient(135deg,rgba(90,79,243,.12),rgba(139,124,255,.08));border:1px solid rgba(90,79,243,.18)}
    .teacher-current-class-label{display:flex;align-items:center;gap:8px;margin:0 0 10px;font-size:.78rem;font-weight:900;color:#5a4ff3;text-transform:uppercase;letter-spacing:.04em}
    .teacher-current-class-name{display:block;font-weight:950;color:#172033;line-height:1.15;margin-bottom:12px}
    .teacher-class-switch label{font-size:.78rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .invite-code{font-size:1.35rem;letter-spacing:.12em;font-weight:900;background:#172033;color:#fff;border-radius:16px;padding:12px 16px;display:inline-block}
    .qr-placeholder{width:180px;height:180px;border-radius:24px;background:repeating-linear-gradient(45deg,#172033 0 8px,#fff 8px 16px);box-shadow:inset 0 0 0 16px #fff;border:1px solid rgba(23,32,51,.12)}
    .teacher-global-layout{grid-template-columns:minmax(0,1fr)}
    .teacher-global-layout .admin-main{width:100%;max-width:1360px;margin:0 auto}
    .teacher-global-brand{display:inline-flex;align-items:center;gap:10px;color:var(--ev);font-weight:950;font-size:1.05rem;letter-spacing:-.03em;text-decoration:none;margin-bottom:10px}
    .teacher-global-brand span{width:30px;height:30px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#5a4ff3,#8b7cff);color:#fff;font-size:.9rem;box-shadow:0 10px 26px rgba(90,79,243,.22)}
  </style>
</head>
<body>
<div class="admin-layout <?= $showClassSidebar ? '' : 'teacher-global-layout' ?>">
  <?php if ($showClassSidebar): ?>
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
      <a class="<?= teacher_active('contents.php') ?>" href="<?= teacher_h($withClass('contents.php')) ?>">📚 Lerninhalte</a>
      <a class="<?= teacher_active('students.php') ?>" href="<?= teacher_h($withClass('students.php')) ?>">👧 Schüler</a>
      <a class="<?= teacher_active('quizzes.php') ?>" href="<?= teacher_h($withClass('quizzes.php')) ?>">📝 Klassen-Quizzes</a>
      <a class="<?= teacher_active('ai_wizard.php') ?>" href="<?= teacher_h($withClass('ai_wizard.php')) ?>">✨ KI-Wizard</a>
      <a class="<?= teacher_active('live.php') ?>" href="<?= teacher_h($withClass('live.php')) ?>">⚡ Live Quizz</a>
      <a class="<?= teacher_active('settings.php') ?>" href="<?= teacher_h($withClass('settings.php')) ?>">⚙️ Einstellungen</a>
      <a class="<?= teacher_active('classes.php') ?>" href="classes.php">🏫 Klassen</a>
    </nav>

    <div class="admin-role-box mt-4">
      <div class="teacher-sidebar-user">
        <span class="teacher-user-avatar"><?= teacher_h($initials) ?></span>
        <div>
          <strong><?= teacher_h($displayName) ?></strong>
          <span><?= teacher_h($roleLabel) ?> · kostenpflichtig</span>
        </div>
      </div>
    </div>


  </aside>
  <?php endif; ?>

  <main class="admin-main">
    <?php if (!$showClassSidebar): ?>
      <a class="teacher-global-brand" href="/teacher/index.php"><span>E</span> Elevaro Lehrer</a>
    <?php endif; ?>
    <header class="admin-page-head">
      <div>
        <h1><?= teacher_h($title) ?></h1>
        <?php if ($subtitle): ?><p><?= teacher_h($subtitle) ?></p><?php endif; ?>
      </div>
      <div class="teacher-top-actions">
        <?php if ($classId && !$isGlobalLibraryPage): ?><a class="btn btn-outline-primary" href="/classroom.php?class_id=<?= (int)$classId ?>">🚪 Klassenraum betreten</a><?php endif; ?>

        <div class="dropdown teacher-user-dropdown">
          <button class="teacher-user-button" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="teacher-user-avatar"><?= teacher_h($initials) ?></span>
            <span class="teacher-user-meta d-none d-sm-flex">
              <strong><?= teacher_h($displayName) ?></strong>
              <span><?= teacher_h($roleLabel) ?></span>
            </span>
            <span class="text-muted small">⌄</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end teacher-user-menu">
            <li class="dropdown-header">
              <strong><?= teacher_h($displayName) ?></strong>
              <span><?= teacher_h($roleLabel) ?> · Lehreraccount</span>
            </li>
            <li><a class="dropdown-item" href="classes.php">🏫 Meine Klassen</a></li>
            <li><a class="dropdown-item" href="materials.php">🗂️ Meine Quizzes + Materialien</a></li>
            <li><a class="dropdown-item" href="/account.php">👤 Mein Konto</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="/logout.php">↪ Logout</a></li>
          </ul>
        </div>
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
