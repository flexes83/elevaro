<?php
require_once __DIR__ . '/_layout.php';

$pdo = teacher_db();
$teacherId = teacher_current_user_id();
$unitId = (int)($_GET['unit_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM teacher_units WHERE id = :id AND teacher_id = :teacher_id LIMIT 1");
$stmt->execute(['id' => $unitId, 'teacher_id' => $teacherId]);
$unit = $stmt->fetch();
if (!$unit) {
    teacher_header('Listening-Wizard', 'Unit nicht gefunden.');
    echo '<div class="alert alert-warning">Diese Unit wurde nicht gefunden oder gehört nicht zu deinem Account.</div><a class="btn btn-primary" href="materials.php">Zur Bibliothek</a>';
    teacher_footer();
    exit;
}
function teacher_listening_is_language(?string $subjectCode): bool
{
    $code = mb_strtolower(trim((string)$subjectCode));
    return in_array($code, ['englisch','english','en','franzoesisch','französisch','french','fr','spanisch','spanish','es','italienisch','italian','it','latein','latin','la'], true);
}
$isLanguage = teacher_listening_is_language($unit['subject_code'] ?? '');
teacher_header('Listening-Wizard', 'Hör- und Leseverständnis für die Unit „' . (string)$unit['title'] . '“ erstellen.');
?>
<style>
  .listening-wizard{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:20px}.listening-card{background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:30px;padding:24px;box-shadow:0 18px 52px rgba(23,32,51,.07)}.listening-card h3{font-weight:950;color:#172033}.listening-steps{display:grid;gap:12px}.listening-step{display:flex;gap:12px;padding:14px;border-radius:20px;background:#f8fafc;border:1px solid rgba(23,32,51,.07)}.listening-step span{width:34px;height:34px;border-radius:14px;background:#eef2ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-weight:950}.listening-actions .btn{border-radius:999px;font-weight:900}@media(max-width:950px){.listening-wizard{grid-template-columns:1fr}}
</style>
<?php if (!$isLanguage): ?>
  <div class="alert alert-warning"><strong>Listening ist nur für Fremdsprachen verfügbar.</strong><br>Diese Unit ist aktuell keinem Fremdsprachen-Fach zugeordnet.</div>
  <a class="btn btn-primary" href="materials.php">Zurück zur Bibliothek</a>
<?php else: ?>
  <div class="listening-wizard">
    <section class="listening-card">
      <h3>Listening + Comprehension erstellen</h3>
      <p class="text-muted">Elevaro erstellt zuerst ein Transcript. Daraus können anschließend Audio, Hörverständnis-Fragen, Leseverständnis und ein PDF-Arbeitsblatt entstehen.</p>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-bold">Dauer</label><select class="form-select"><option>2 Minuten</option><option selected>3–4 Minuten</option><option>5 Minuten</option></select></div>
        <div class="col-md-6"><label class="form-label fw-bold">Niveau</label><select class="form-select"><option>A1</option><option selected>A2</option><option>B1</option><option>B2</option></select></div>
        <div class="col-12"><label class="form-label fw-bold">Schwerpunkt / Wortschatz</label><textarea class="form-control" rows="4" placeholder="Optional: Thema, Wortschatz, Grammatik, Situation..."></textarea></div>
      </div>
      <div class="listening-actions mt-4"><button class="btn btn-primary btn-lg" type="button">✨ Transcript & Aufgaben erzeugen</button></div>
    </section>
    <aside class="listening-card">
      <h3>Was entsteht?</h3>
      <div class="listening-steps">
        <div class="listening-step"><span>1</span><div><strong>Transcript</strong><br><small class="text-muted">3–4 Minuten Unterrichtstext</small></div></div>
        <div class="listening-step"><span>2</span><div><strong>Audio</strong><br><small class="text-muted">für Hörverständnis</small></div></div>
        <div class="listening-step"><span>3</span><div><strong>Comprehension</strong><br><small class="text-muted">Fragen als Quiz oder PDF</small></div></div>
        <div class="listening-step"><span>4</span><div><strong>Reading-Variante</strong><br><small class="text-muted">Transcript als Leseverständnis</small></div></div>
      </div>
    </aside>
  </div>
<?php endif; ?>
<?php teacher_footer(); ?>
