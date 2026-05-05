<?php
require_once __DIR__ . '/_layout.php';

$pdo = teacher_db();
$error = null;
$notice = null;
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $classes = teacher_classes();
        if (count($classes) >= 3) {
            throw new RuntimeException('Du kannst mit diesem Lehrer-Account maximal 3 Klassen anlegen.');
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $state = strtoupper(trim((string)($_POST['state_code'] ?? 'BW')));
        $schoolType = trim((string)($_POST['school_type_code'] ?? ''));
        $levelKey = trim((string)($_POST['level_key'] ?? ($_POST['grade'] ?? '')));
        $grade = (int)($_POST['grade'] ?? 0);
        $subject = trim((string)($_POST['subject_code'] ?? ''));

        if ($name === '' || $state === '' || $schoolType === '' || $levelKey === '' || $subject === '') {
            throw new RuntimeException('Bitte alle Pflichtfelder ausfüllen.');
        }

        $code = teacher_make_invite_code();
        $stmt = $pdo->prepare("INSERT INTO teacher_classes (teacher_id, name, state_code, school_type_code, level_key, grade, subject_code, invite_code) VALUES (:teacher_id, :name, :state_code, :school_type_code, :level_key, :grade, :subject_code, :invite_code)");
        $stmt->execute([
            'teacher_id' => teacher_current_user_id(),
            'name' => $name,
            'state_code' => $state,
            'school_type_code' => $schoolType,
            'level_key' => $levelKey,
            'grade' => $grade ?: null,
            'subject_code' => $subject,
            'invite_code' => $code,
        ]);
        $classId = (int)$pdo->lastInsertId();

        $fields = ['code' => $code, 'teacher_id' => teacher_current_user_id(), 'label' => $name, 'max_students' => 90, 'max_classes' => 3, 'max_quizzes_per_class' => 10];
        if (teacher_column_exists('class_codes', 'class_id')) {
            $fields['class_id'] = $classId;
        }
        $columns = array_keys($fields);
        $pdo->prepare('INSERT INTO class_codes (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')')->execute($fields);

        header('Location: index.php?class_id=' . $classId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$classes = teacher_classes();
$states = [];
try { $states = curriculum_states(); } catch (Throwable $e) { $states = []; }
$schoolTypes = [];
try { $schoolTypes = curriculum_school_types('BW'); } catch (Throwable $e) { $schoolTypes = []; }
$subjects = [];
try { $subjects = curriculum_subjects('BW', $schoolTypes[0]['code'] ?? 'grundschule', 1); } catch (Throwable $e) { $subjects = []; }

teacher_header('Klassen', 'Bis zu 3 Klassen pro Lehrer-Account anlegen.');
?>
<?php if ($notice): ?><div class="alert alert-success"><?= teacher_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= teacher_h($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card card-soft"><div class="card-body p-4">
      <h2 class="h5 fw-bold">Neue Klasse</h2>
      <p class="text-muted">Bundesland ist später aus dem Account vorausgewählt. Für den MVP bleibt es hier editierbar.</p>
      <?php if (count($classes) >= 3): ?>
        <div class="alert alert-warning">Maximal 3 Klassen erreicht.</div>
      <?php else: ?>
      <form method="post" class="row g-3">
        <div class="col-12"><label class="form-label">Klassenname</label><input class="form-control" name="name" placeholder="z. B. 5a Mathematik" required></div>
        <div class="col-md-6"><label class="form-label">Bundesland</label><select class="form-select" name="state_code"><option value="BW">BW</option><?php foreach($states as $s): ?><option value="<?= teacher_h($s['code']) ?>"><?= teacher_h($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Schulform</label><input class="form-control" name="school_type_code" value="<?= teacher_h($schoolTypes[0]['code'] ?? 'grundschule') ?>" required></div>
        <div class="col-md-6"><label class="form-label">Klasse / Stufe</label><input class="form-control" name="level_key" value="1" required></div>
        <div class="col-md-6"><label class="form-label">Klasse numerisch</label><input class="form-control" type="number" name="grade" value="1" min="1" max="13"></div>
        <div class="col-12"><label class="form-label">Fach</label><input class="form-control" name="subject_code" value="<?= teacher_h($subjects[0]['code'] ?? 'mathematik') ?>" required></div>
        <div class="col-12"><button class="btn btn-primary">Klasse anlegen</button></div>
      </form>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card card-soft admin-table-card"><div class="card-body p-4">
      <h2 class="h5 fw-bold">Meine Klassen</h2>
      <table class="table"><thead><tr><th>Klasse</th><th>Kontext</th><th>Code</th><th></th></tr></thead><tbody>
        <?php foreach ($classes as $class): ?>
          <tr><td><strong><?= teacher_h($class['name']) ?></strong></td><td><?= teacher_h($class['state_code'].' · '.$class['school_type_code'].' · '.$class['level_key'].' · '.$class['subject_code']) ?></td><td><code><?= teacher_h($class['invite_code']) ?></code></td><td class="text-end"><a class="btn btn-sm btn-primary" href="index.php?class_id=<?= (int)$class['id'] ?>">Öffnen</a></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    </div></div>
  </div>
</div>
<?php teacher_footer(); ?>
