<?php
require_once __DIR__ . '/_bootstrap.php';

function admin_header(string $title, string $subtitle = ''): void {
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= admin_h($title) ?> – Elevaro Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/admin.css" rel="stylesheet">

<link rel="stylesheet" href="../assets/css/design-system.css">
</head>
<body>
<div class="admin-layout">
  <aside class="admin-sidebar">
    <a class="admin-brand" href="index.php">Elevaro</a>
    <nav class="admin-nav">
      <a class="<?= admin_active('index.php') ?>" href="index.php">🏠 Dashboard</a>
      <a class="<?= admin_active('ai_curriculum_wizard.php') ?>" href="ai_curriculum_wizard.php">✨ KI Wizard</a>
      <a class="<?= admin_active('ai_batches.php') ?>" href="ai_batches.php">📦 KI-Auswahlen</a>
      <a class="<?= admin_active('curriculum_topics.php') ?>" href="curriculum_topics.php">📚 Curriculum</a>
      <a class="<?= admin_active('quizzes.php') ?>" href="quizzes.php">📝 Quizze</a>
    </nav>
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