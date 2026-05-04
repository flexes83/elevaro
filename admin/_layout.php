<?php
require_once __DIR__ . '/_bootstrap.php';

function admin_header(string $title, string $subtitle = ''): void {
    $user = auth_user();
    $realRole = auth_real_role();
    $effectiveRole = auth_effective_role();
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= admin_h($title) ?> – Elevaro Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/admin.css" rel="stylesheet">
</head>
<body>
<div class="admin-layout">
  <aside class="admin-sidebar">
    <a class="admin-brand" href="index.php">Elevaro</a>
    <nav class="admin-nav">
    <a href="moderation.php">Qualitätsprüfung</a>
    <a href="codes.php">Codes</a>
      <a class="<?= admin_active('index.php') ?>" href="index.php">🏠 Dashboard</a>
      <a class="<?= admin_active('ai_curriculum_wizard.php') ?>" href="ai_curriculum_wizard.php">✨ KI Wizard</a>
      <a class="<?= admin_active('ai_batches.php') ?>" href="ai_batches.php">📦 KI-Auswahlen</a>
      <a class="<?= admin_active('curriculum_topics.php') ?>" href="curriculum_topics.php">📚 Curriculum</a>
      <a class="<?= admin_active('quizzes.php') ?>" href="quizzes.php">📝 Quizze</a>
    </nav>

    <div class="admin-role-box">
      <div class="admin-role-user">
        <strong><?= admin_h($user['display_name'] ?: $user['username'] ?: $user['email']) ?></strong>
        <span><?= admin_h(auth_role_label((string)$realRole)) ?></span>
      </div>

      <form method="post" action="/switch_role.php" class="admin-role-form">
        <input type="hidden" name="return" value="<?= admin_h($_SERVER['REQUEST_URI'] ?? '/admin/index.php') ?>">
        <label>Testansicht</label>
        <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach (auth_roles() as $role): ?>
            <option value="<?= admin_h($role) ?>" <?= $effectiveRole === $role ? 'selected' : '' ?>>
              <?= admin_h(auth_role_label($role)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>

      <?php if ($effectiveRole !== $realRole): ?>
        <form method="post" action="/switch_role.php" class="mt-2">
          <input type="hidden" name="return" value="<?= admin_h($_SERVER['REQUEST_URI'] ?? '/admin/index.php') ?>">
          <input type="hidden" name="role" value="reset">
          <button class="btn btn-sm btn-outline-light w-100">Simulation beenden</button>
        </form>
      <?php endif; ?>

      <a class="admin-logout" href="/logout.php">Logout</a>
    </div>

    <div class="admin-sidebar-footer">Spielerisch zu guten Noten.</div>
  </aside>

  <main class="admin-main">
    <header class="admin-page-head">
      <div>
        <h1><?= admin_h($title) ?></h1>
        <?php if ($subtitle): ?><p><?= admin_h($subtitle) ?></p><?php endif; ?>
      </div>
      <a class="btn btn-primary" href="ai_curriculum_wizard.php">✨ Neues KI-Set</a>
    </header>
<?php }

function admin_footer(): void { ?>
  </main>
</div>
</body>
</html>
<?php } ?>
