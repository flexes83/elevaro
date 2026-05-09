<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/library_units.php';

$unitId = (int)($_GET['unit_id'] ?? 0);
$unit = $unitId ? teacher_library_unit_by_id($unitId) : null;
if ($unitId && !$unit) {
    teacher_header('Listening erstellen', 'Die angeforderte Unit wurde nicht gefunden.');
    echo '<div class="alert alert-danger rounded-4">Diese Unit gehört nicht zu deinem Account oder existiert nicht mehr.</div>';
    teacher_footer();
    exit;
}

$isForeignLanguage = $unit ? teacher_library_is_foreign_language((string)$unit['subject_code'], (string)$unit['subject_label']) : true;
teacher_header('Listening erstellen', $unit ? 'Hör- und Leseverständnis für die Unit „' . (string)$unit['title'] . '“.' : 'Erstelle ein Listening mit Transcript und Comprehension-Aufgaben.');
?>
<style>
  .listening-wizard{display:grid;grid-template-columns:minmax(0,430px) minmax(0,1fr);gap:22px}.listen-card{background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:28px;box-shadow:0 18px 48px rgba(23,32,51,.065);padding:22px}.listen-card h2{font-size:1.12rem;font-weight:950;margin:0 0 8px}.listen-steps{display:grid;gap:10px;margin-top:18px}.listen-step{display:flex;gap:12px;align-items:flex-start;padding:13px;border-radius:18px;background:#f8fafc;border:1px solid rgba(23,32,51,.06)}.listen-step span{width:30px;height:30px;border-radius:12px;background:#f3f1ff;color:#4f46e5;display:inline-flex;align-items:center;justify-content:center;font-weight:950}.transcript-preview{min-height:520px;background:linear-gradient(135deg,#f8fafc,#eef2ff);border-radius:28px;padding:22px}.transcript-paper{background:#fff;border-radius:22px;padding:24px;box-shadow:0 22px 70px rgba(23,32,51,.12)}.audio-bar{height:58px;border-radius:999px;background:#172033;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 18px;margin:18px 0}.level-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.level-grid .form-control,.level-grid .form-select{border-radius:999px}@media(max-width:1050px){.listening-wizard{grid-template-columns:1fr}}
</style>

<?php if (!$isForeignLanguage): ?>
  <div class="alert alert-warning rounded-4"><strong>Listening ist nur für Fremdsprachen aktiv.</strong><br>Diese Unit ist aktuell <?= teacher_h((string)$unit['subject_label']) ?>. Du kannst stattdessen ein Quiz oder Arbeitsblatt erstellen.</div>
<?php else: ?>
<div class="listening-wizard">
  <aside class="listen-card">
    <h2>Listening konfigurieren</h2>
    <p class="text-muted">Erzeuge einen 3–4-minütigen Hörtext mit Transcript. Daraus können Hörverständnis, Leseverständnis, Quiz und Arbeitsblatt entstehen.</p>

    <?php if ($unit): ?>
      <div class="alert alert-light border rounded-4"><strong>Unit:</strong> <?= teacher_h((string)$unit['title']) ?><br><small><?= teacher_h((string)$unit['subject_label']) ?><?= $unit['grade'] ? ' · Klasse ' . teacher_h((string)$unit['grade']) : '' ?></small></div>
    <?php endif; ?>

    <div class="level-grid mb-3">
      <div><label class="form-label fw-bold">Dauer</label><select class="form-select"><option>3 Minuten</option><option selected>4 Minuten</option><option>2 Minuten</option></select></div>
      <div><label class="form-label fw-bold">Niveau</label><select class="form-select"><option>A1</option><option selected>A2</option><option>B1</option><option>B2</option></select></div>
    </div>
    <div class="mb-3"><label class="form-label fw-bold">Schwerpunkt / Wortschatz</label><textarea class="form-control rounded-4" rows="5" placeholder="z. B. Alltagssituation, bestimmte Vokabeln, simple past, keine schwierigen Nebensätze …"></textarea></div>

    <button class="btn btn-primary w-100 rounded-pill fw-bold" type="button">✨ Transcript erzeugen</button>

    <div class="listen-steps">
      <div class="listen-step"><span>1</span><div><strong>Transcript</strong><br><small class="text-muted">Text prüfen und bei Bedarf anpassen.</small></div></div>
      <div class="listen-step"><span>2</span><div><strong>Audio</strong><br><small class="text-muted">Stimme und Tempo wählen.</small></div></div>
      <div class="listen-step"><span>3</span><div><strong>Aufgaben</strong><br><small class="text-muted">Listening-Quiz oder Worksheet erstellen.</small></div></div>
    </div>
  </aside>

  <section class="transcript-preview">
    <div class="transcript-paper">
      <h2 class="fw-black">Transcript-Vorschau</h2>
      <p class="text-muted">Hier erscheint der KI-generierte Hörtext. Sobald ein Transcript vorhanden ist, kann Elevaro daraus auch ein Leseverständnis und Arbeitsblatt ableiten.</p>
      <div class="audio-bar"><span>▶ Preview Audio</span><span>03:45</span></div>
      <p><strong><?= teacher_h($unit ? (string)$unit['title'] : 'Listening Title') ?></strong></p>
      <p class="text-muted">Der Hörtext wird hier absatzweise angezeigt. Lehrkräfte können ihn prüfen, kürzen oder vereinfachen, bevor Audio und Aufgaben erstellt werden.</p>
      <div class="d-flex gap-2 flex-wrap mt-4">
        <button class="btn btn-primary rounded-pill" type="button">🎧 Hörverständnis-Aufgaben</button>
        <button class="btn btn-outline-primary rounded-pill" type="button">📖 Leseverständnis</button>
        <button class="btn btn-outline-primary rounded-pill" type="button">📄 Arbeitsblatt</button>
      </div>
    </div>
  </section>
</div>
<?php endif; ?>
<?php teacher_footer(); ?>
