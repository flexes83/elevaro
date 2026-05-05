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
        $levelKey = trim((string)($_POST['level_key'] ?? ''));
        $subject = trim((string)($_POST['subject_code'] ?? ''));

        if ($name === '' || $state === '' || $schoolType === '' || $levelKey === '' || $subject === '') {
            throw new RuntimeException('Bitte alle Pflichtfelder ausfüllen.');
        }

        $levelContext = curriculum_context_from_level($state, $schoolType, $levelKey);
        $grade = (int)($levelContext['numeric_grade'] ?? 0);

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
$accountState = strtoupper((string)($user['state_code'] ?? $user['state'] ?? 'BW'));
$states = [];
try { $states = curriculum_states(); } catch (Throwable $e) { $states = []; }
$schoolTypes = [];
try { $schoolTypes = curriculum_school_types($accountState); } catch (Throwable $e) { $schoolTypes = []; }
$defaultSchoolType = (string)($schoolTypes[0]['code'] ?? 'grundschule');
$levels = [];
try { $levels = curriculum_levels($accountState, $defaultSchoolType); } catch (Throwable $e) { $levels = []; }
$defaultLevel = (string)($levels[0]['code'] ?? $levels[0]['grade'] ?? '1');
$subjects = [];
try { $subjects = curriculum_subjects($accountState, $defaultSchoolType, $defaultLevel); } catch (Throwable $e) { $subjects = []; }
$initialIsVocational = false;
try { $initialIsVocational = curriculum_is_vocational_school_type($accountState, $defaultSchoolType); } catch (Throwable $e) {}

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
      <form method="post" class="row g-3" id="teacherClassForm">
        <div class="col-12"><label class="form-label">Klassenname</label><input class="form-control" name="name" placeholder="z. B. 5a Mathematik" required></div>
        <div class="col-md-6"><label class="form-label">Bundesland</label><select class="form-select" name="state_code" id="stateSelect" required><?php foreach($states as $s): ?><option value="<?= teacher_h($s['code']) ?>" <?= (string)$s['code'] === $accountState ? 'selected' : '' ?>><?= teacher_h($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Schulform</label><select class="form-select" name="school_type_code" id="schoolTypeSelect" required><?php foreach($schoolTypes as $type): ?><option value="<?= teacher_h($type['code']) ?>"><?= teacher_h($type['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" id="levelLabel"><?= $initialIsVocational ? 'Kurs / Stufe' : 'Klasse' ?></label><select class="form-select" name="level_key" id="levelSelect" required><?php foreach($levels as $level): ?><option value="<?= teacher_h($level['code'] ?? $level['grade']) ?>" data-grade="<?= teacher_h($level['numeric_grade'] ?? $level['grade'] ?? '') ?>"><?= teacher_h($level['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Fach</label><select class="form-select" name="subject_code" id="subjectSelect" required><?php foreach($subjects as $subject): ?><option value="<?= teacher_h($subject['code']) ?>"><?= teacher_h(($subject['icon'] ?? '') . ' ' . $subject['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><p class="small text-muted mb-0" id="classModeHint"><?= $initialIsVocational ? 'Für diese Schulform wählst du einen Kurs bzw. eine Stufe, keine zusätzliche numerische Klasse.' : 'Für diese Schulform wählst du eine Klasse. Eine separate numerische Eingabe ist nicht nötig.' ?></p></div>
        <div class="col-12"><button class="btn btn-primary">Klasse anlegen</button></div>
      </form>
      <script>
      (() => {
        const stateSelect = document.getElementById('stateSelect');
        const schoolTypeSelect = document.getElementById('schoolTypeSelect');
        const levelSelect = document.getElementById('levelSelect');
        const subjectSelect = document.getElementById('subjectSelect');
        const levelLabel = document.getElementById('levelLabel');
        const hint = document.getElementById('classModeHint');

        const setOptions = (select, items, valueKey = 'code', labelBuilder = null) => {
          select.innerHTML = '';
          items.forEach((item) => {
            const option = document.createElement('option');
            option.value = item[valueKey] ?? item.code ?? item.grade ?? '';
            option.textContent = labelBuilder ? labelBuilder(item) : (item.name ?? item.code ?? item.grade ?? '');
            if (item.numeric_grade || item.grade) option.dataset.grade = item.numeric_grade ?? item.grade;
            select.appendChild(option);
          });
        };

        const loadJson = async (params) => {
          const url = 'api/curriculum_options.php?' + new URLSearchParams(params).toString();
          const response = await fetch(url, {headers: {'Accept': 'application/json'}});
          const data = await response.json();
          if (!data.ok) throw new Error(data.error || 'Optionen konnten nicht geladen werden.');
          return data;
        };

        const loadSchoolTypes = async () => {
          const data = await loadJson({mode: 'school_types', state: stateSelect.value});
          setOptions(schoolTypeSelect, data.items);
          await loadLevels();
        };

        const loadLevels = async () => {
          const data = await loadJson({mode: 'levels', state: stateSelect.value, school_type: schoolTypeSelect.value});
          setOptions(levelSelect, data.items);
          levelLabel.textContent = data.label || 'Klasse';
          hint.textContent = data.school_mode === 'course'
            ? 'Für diese Schulform wählst du einen Kurs bzw. eine Stufe, keine zusätzliche numerische Klasse.'
            : 'Für diese Schulform wählst du eine Klasse. Eine separate numerische Eingabe ist nicht nötig.';
          await loadSubjects();
        };

        const loadSubjects = async () => {
          const data = await loadJson({mode: 'subjects', state: stateSelect.value, school_type: schoolTypeSelect.value, level: levelSelect.value});
          setOptions(subjectSelect, data.items, 'code', (item) => `${item.icon ? item.icon + ' ' : ''}${item.name ?? item.code}`);
        };

        stateSelect?.addEventListener('change', loadSchoolTypes);
        schoolTypeSelect?.addEventListener('change', loadLevels);
        levelSelect?.addEventListener('change', loadSubjects);
      })();
      </script>
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
