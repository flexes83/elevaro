<?php
require_once __DIR__ . '/_layout.php';

$pdo = teacher_db();
$teacherId = teacher_current_user_id();
$unitId = (int)($_GET['unit_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM teacher_units WHERE id = :id AND teacher_id = :teacher_id LIMIT 1");
$stmt->execute(['id' => $unitId, 'teacher_id' => $teacherId]);
$unit = $stmt->fetch();
if (!$unit) {
    teacher_header('Arbeitsblatt-Editor', 'Unit nicht gefunden.');
    echo '<div class="alert alert-warning">Diese Unit wurde nicht gefunden oder gehört nicht zu deinem Account.</div><a class="btn btn-primary" href="materials.php">Zur Bibliothek</a>';
    teacher_footer();
    exit;
}

teacher_header('Arbeitsblatt-Editor', 'Lernmaterial für die Unit „' . (string)$unit['title'] . '“ erstellen.');
?>
<style>
  .worksheet-builder{display:grid;grid-template-columns:360px minmax(0,1fr);gap:20px}.worksheet-panel,.worksheet-preview{background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:30px;box-shadow:0 18px 52px rgba(23,32,51,.07)}.worksheet-panel{padding:22px}.worksheet-panel h3,.worksheet-preview h3{font-weight:950;color:#172033}.layout-choice{display:grid;gap:10px;margin:12px 0}.layout-choice label{display:flex;align-items:center;gap:10px;border:1px solid rgba(23,32,51,.08);border-radius:18px;padding:12px;background:#f8fafc;font-weight:850}.worksheet-preview{padding:26px;background:linear-gradient(135deg,#fff,#f8fafc)}.paper{background:#fff;border-radius:18px;min-height:620px;padding:34px;box-shadow:0 22px 60px rgba(23,32,51,.12);border:1px solid rgba(23,32,51,.08)}.paper h2{font-weight:950}.question-row{border:1px solid rgba(23,32,51,.08);border-radius:16px;padding:12px;margin-top:12px}.question-row select{max-width:170px;border-radius:999px}.editor-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.editor-toolbar .btn{border-radius:999px;font-weight:900}@media(max-width:1050px){.worksheet-builder{grid-template-columns:1fr}}
</style>
<div class="worksheet-builder">
  <aside class="worksheet-panel">
    <h3>Arbeitsblatt konfigurieren</h3>
    <p class="text-muted">Wähle Layout, Umfang und Fragetypen. Die KI-Generierung wird im nächsten Schritt hier angeschlossen.</p>
    <label class="form-label fw-bold">Layout</label>
    <div class="layout-choice">
      <label><input class="form-check-input" type="radio" name="layout" checked> Klassisch kompakt</label>
      <label><input class="form-check-input" type="radio" name="layout"> Modern mit Infobox</label>
      <label><input class="form-check-input" type="radio" name="layout"> Schulbuchstil</label>
    </div>
    <label class="form-label fw-bold mt-3">Anzahl Fragen</label>
    <select class="form-select" id="worksheetQuestionCount"><option>8</option><option selected>10</option><option>12</option><option>15</option></select>
    <label class="form-label fw-bold mt-3">Standard-Fragetyp</label>
    <select class="form-select"><option>Multiple Choice</option><option>Freitext</option><option>Lückentext</option></select>
    <div class="editor-toolbar">
      <button class="btn btn-primary" type="button">✨ Fragen mit KI erzeugen</button>
      <button class="btn btn-light" type="button">＋ Eigene Frage</button>
    </div>
  </aside>
  <section class="worksheet-preview">
    <h3>Vorschau</h3>
    <div class="paper mt-3">
      <h2><?= teacher_h($unit['title']) ?></h2>
      <p class="text-muted">Name: ____________________ Datum: ____________</p>
      <div class="question-row"><div class="d-flex justify-content-between gap-3"><strong>1. Beispiel-Frage wird hier angezeigt</strong><select class="form-select form-select-sm"><option>Multiple Choice</option><option>Freitext</option><option>Lückentext</option></select></div><p class="mb-0 mt-2 text-muted">Antwortmöglichkeiten oder Schreiblinien erscheinen je nach Fragetyp.</p></div>
      <div class="question-row"><div class="d-flex justify-content-between gap-3"><strong>2. Eigene Fragen können ergänzt werden</strong><select class="form-select form-select-sm"><option>Freitext</option><option>Multiple Choice</option><option>Lückentext</option></select></div><p class="mb-0 mt-2 text-muted">Diese Vorschau ist der Einstieg für den späteren PDF-Export.</p></div>
    </div>
  </section>
</div>
<?php teacher_footer(); ?>
