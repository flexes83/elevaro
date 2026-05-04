<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/includes/access.php';

$pdo = admin_db();
$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_premium_code') {
            $code = strtoupper(trim($_POST['code'] ?? '')) ?: strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare("
                INSERT INTO premium_access_codes (code, label, months, max_uses, expires_at)
                VALUES (:code, :label, :months, :max_uses, :expires_at)
            ")->execute([
                'code' => $code,
                'label' => trim($_POST['label'] ?? '') ?: null,
                'months' => max(1, (int)($_POST['months'] ?? 3)),
                'max_uses' => max(1, (int)($_POST['max_uses'] ?? 1)),
                'expires_at' => trim($_POST['expires_at'] ?? '') ?: null,
            ]);
            $notice = 'Freischaltcode erstellt: ' . $code;
        }

        if ($action === 'create_class_code') {
            $code = strtoupper(trim($_POST['code'] ?? '')) ?: strtoupper(bin2hex(random_bytes(4)));
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            if (!$teacherId) {
                throw new RuntimeException('teacher_id fehlt.');
            }
            $pdo->prepare("
                INSERT INTO class_codes (code, teacher_id, label, max_students, max_classes, max_quizzes_per_class, expires_at)
                VALUES (:code, :teacher_id, :label, :max_students, :max_classes, :max_quizzes_per_class, :expires_at)
            ")->execute([
                'code' => $code,
                'teacher_id' => $teacherId,
                'label' => trim($_POST['label'] ?? '') ?: null,
                'max_students' => max(1, (int)($_POST['max_students'] ?? 90)),
                'max_classes' => max(1, (int)($_POST['max_classes'] ?? 3)),
                'max_quizzes_per_class' => max(1, (int)($_POST['max_quizzes_per_class'] ?? 10)),
                'expires_at' => trim($_POST['expires_at'] ?? '') ?: null,
            ]);
            $notice = 'Klassencode erstellt: ' . $code;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$premiumCodes = elevaro_access_table_exists('premium_access_codes')
    ? $pdo->query("SELECT * FROM premium_access_codes ORDER BY created_at DESC LIMIT 50")->fetchAll()
    : [];

$classCodes = elevaro_access_table_exists('class_codes')
    ? $pdo->query("SELECT cc.*, u.display_name AS teacher_name, u.email AS teacher_email FROM class_codes cc LEFT JOIN auth_users u ON u.id = cc.teacher_id ORDER BY cc.created_at DESC LIMIT 50")->fetchAll()
    : [];

$teachers = $pdo->query("SELECT id, display_name, email FROM auth_users WHERE role IN ('lehrer','admin') ORDER BY display_name, email")->fetchAll();

admin_header('Codes', 'Freischaltcodes und Klassencodes verwalten.');
?>

<?php if ($notice): ?><div class="alert alert-success"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= admin_h($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card card-soft">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold">Freischaltcode erstellen</h2>
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="create_premium_code">
          <div class="col-md-6"><label class="form-label">Code optional</label><input class="form-control" name="code" placeholder="AUTO"></div>
          <div class="col-md-6"><label class="form-label">Label</label><input class="form-control" name="label" placeholder="Beta / Sozial / Test"></div>
          <div class="col-md-4"><label class="form-label">Monate</label><input class="form-control" type="number" name="months" value="3"></div>
          <div class="col-md-4"><label class="form-label">Max. Nutzungen</label><input class="form-control" type="number" name="max_uses" value="1"></div>
          <div class="col-md-4"><label class="form-label">Gültig bis</label><input class="form-control" type="datetime-local" name="expires_at"></div>
          <div class="col-12"><button class="btn btn-primary">Code erstellen</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card card-soft">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold">Klassencode erstellen</h2>
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="create_class_code">
          <div class="col-md-6"><label class="form-label">Code optional</label><input class="form-control" name="code" placeholder="AUTO"></div>
          <div class="col-md-6"><label class="form-label">Label</label><input class="form-control" name="label" placeholder="5a Gymnasium"></div>
          <div class="col-12">
            <label class="form-label">Lehrer</label>
            <select class="form-select" name="teacher_id" required>
              <option value="">Bitte wählen</option>
              <?php foreach ($teachers as $teacher): ?>
                <option value="<?= (int)$teacher['id'] ?>"><?= admin_h(($teacher['display_name'] ?: $teacher['email']) . ' · #' . $teacher['id']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label">Max. Schüler</label><input class="form-control" type="number" name="max_students" value="90"></div>
          <div class="col-md-4"><label class="form-label">Max. Klassen</label><input class="form-control" type="number" name="max_classes" value="3"></div>
          <div class="col-md-4"><label class="form-label">Quizze/Klasse</label><input class="form-control" type="number" name="max_quizzes_per_class" value="10"></div>
          <div class="col-12"><button class="btn btn-primary">Klassencode erstellen</button></div>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card card-soft mt-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold">Freischaltcodes</h2>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Code</th><th>Label</th><th>Monate</th><th>Nutzung</th><th>Aktiv</th></tr></thead>
        <tbody>
        <?php foreach ($premiumCodes as $c): ?>
          <tr><td><code><?= admin_h($c['code']) ?></code></td><td><?= admin_h($c['label']) ?></td><td><?= (int)$c['months'] ?></td><td><?= (int)$c['used_count'] ?>/<?= (int)$c['max_uses'] ?></td><td><?= (int)$c['is_active'] ? 'ja' : 'nein' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card card-soft mt-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold">Klassencodes</h2>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Code</th><th>Label</th><th>Lehrer</th><th>Limit</th><th>Aktiv</th></tr></thead>
        <tbody>
        <?php foreach ($classCodes as $c): ?>
          <tr><td><code><?= admin_h($c['code']) ?></code></td><td><?= admin_h($c['label']) ?></td><td><?= admin_h($c['teacher_name'] ?: $c['teacher_email']) ?></td><td><?= (int)$c['max_students'] ?> Schüler</td><td><?= (int)$c['is_active'] ? 'ja' : 'nein' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php admin_footer(); ?>
