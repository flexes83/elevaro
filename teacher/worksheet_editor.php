<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/library_units.php';

$unitId = (int)($_GET['unit_id'] ?? 0);
$unit = $unitId ? teacher_library_unit_by_id($unitId) : null;
if ($unitId && !$unit) {
    teacher_header('Arbeitsblatt erstellen', 'Die angeforderte Unit wurde nicht gefunden.');
    echo '<div class="alert alert-danger rounded-4">Diese Unit gehört nicht zu deinem Account oder existiert nicht mehr.</div>';
    teacher_footer();
    exit;
}

$classes = teacher_classes();
$matchingClasses = $unit ? teacher_library_classes_matching_unit($unit, $classes) : $classes;

teacher_header('Arbeitsblatt erstellen', $unit ? 'Neues Arbeitsblatt für die Unit „' . (string)$unit['title'] . '“.' : 'Erstelle ein Arbeitsblatt aus einem Quiz oder Lerninhalt.');
?>
<style>
  .worksheet-builder{display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:22px;align-items:start}.worksheet-card{background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:28px;box-shadow:0 18px 48px rgba(23,32,51,.065);padding:22px}.worksheet-card h2{font-size:1.12rem;font-weight:950;margin:0 0 8px}.layout-options{display:grid;gap:10px}.layout-option{display:flex;gap:12px;align-items:center;padding:13px;border-radius:18px;border:1px solid rgba(23,32,51,.09);background:#f8fafc}.layout-preview{width:46px;height:58px;border-radius:10px;background:#fff;border:1px solid rgba(23,32,51,.12);box-shadow:inset 0 -18px 0 rgba(90,79,243,.09)}.worksheet-preview{min-height:640px;background:#f8fafc;border-radius:28px;padding:20px}.paper{max-width:720px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 22px 70px rgba(23,32,51,.14);padding:34px}.paper h1{font-size:1.6rem;font-weight:950;margin-bottom:4px}.paper-meta{color:#64748b;font-weight:750;margin-bottom:24px}.question-row{padding:14px 0;border-top:1px solid #e5e7eb}.question-tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.question-tools .btn{border-radius:999px;font-size:.76rem;font-weight:850}.type-select{max-width:190px;border-radius:999px;font-weight:800}.muted-note{font-size:.88rem;color:#64748b;line-height:1.45}@media(max-width:1050px){.worksheet-builder{grid-template-columns:1fr}}
</style>

<div class="worksheet-builder">
  <aside class="worksheet-card">
    <h2>Arbeitsblatt konfigurieren</h2>
    <p class="muted-note">Ein schlanker Editor: Layout wählen, Fragenanzahl festlegen, Fragetypen anpassen und Fragen bei Bedarf austauschen.</p>

    <?php if ($unit): ?>
      <div class="alert alert-light border rounded-4"><strong>Unit:</strong> <?= teacher_h((string)$unit['title']) ?><br><small><?= teacher_h((string)$unit['subject_label']) ?><?= $unit['grade'] ? ' · Klasse ' . teacher_h((string)$unit['grade']) : '' ?></small></div>
    <?php endif; ?>

    <div class="mb-3">
      <label class="form-label fw-bold">Layout</label>
      <div class="layout-options">
        <label class="layout-option"><input type="radio" name="layout" checked> <span class="layout-preview"></span><span><strong>Modern</strong><br><small class="text-muted">locker, viel Weißraum</small></span></label>
        <label class="layout-option"><input type="radio" name="layout"> <span class="layout-preview"></span><span><strong>Schulbuch</strong><br><small class="text-muted">klassisch und kompakt</small></span></label>
        <label class="layout-option"><input type="radio" name="layout"> <span class="layout-preview"></span><span><strong>Kompakt</strong><br><small class="text-muted">mehr Aufgaben pro Seite</small></span></label>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Anzahl Fragen</label>
      <input class="form-control form-control-lg rounded-pill" type="number" value="10" min="3" max="40">
    </div>

    <div class="mb-3">
      <label class="form-label fw-bold">Klasse zuordnen</label>
      <select class="form-select rounded-pill">
        <option value="">Nicht zuordnen</option>
        <?php foreach ($matchingClasses as $class): ?><option value="<?= (int)$class['id'] ?>"><?= teacher_h(teacher_class_label($class)) ?></option><?php endforeach; ?>
      </select>
    </div>

    <button class="btn btn-primary w-100 rounded-pill fw-bold" type="button">✨ Fragen mit KI erzeugen</button>
    <button class="btn btn-light w-100 rounded-pill fw-bold mt-2" type="button">+ Eigene Frage hinzufügen</button>
  </aside>

  <section class="worksheet-preview">
    <div class="paper">
      <h1><?= teacher_h($unit ? (string)$unit['title'] : 'Neues Arbeitsblatt') ?></h1>
      <div class="paper-meta">Arbeitsblatt · <?= $unit ? teacher_h((string)$unit['subject_label']) : 'Fach' ?><?= $unit && $unit['grade'] ? ' · Klasse ' . teacher_h((string)$unit['grade']) : '' ?></div>
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <div class="question-row">
          <div class="d-flex justify-content-between gap-3 flex-wrap">
            <strong><?= $i ?>. Beispiel-Frage wird hier eingefügt</strong>
            <select class="form-select form-select-sm type-select">
              <option>Multiple Choice</option>
              <option>Freitext</option>
              <option>Lückentext</option>
            </select>
          </div>
          <div class="text-muted mt-2">Antwortmöglichkeiten oder Freitextbereich erscheinen abhängig vom Fragetyp.</div>
          <div class="question-tools">
            <button class="btn btn-sm btn-light" type="button">Frage tauschen</button>
            <button class="btn btn-sm btn-light" type="button">KI verbessern</button>
            <button class="btn btn-sm btn-outline-danger" type="button">Entfernen</button>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </section>
</div>
<?php teacher_footer(); ?>
