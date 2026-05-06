<?php
require_once __DIR__ . '/_layout.php';
$class = teacher_selected_class();
if (!$class) { teacher_header('Einstellungen', 'Lege zuerst eine Klasse an.'); echo '<div class="card card-soft"><div class="card-body p-4"><a class="btn btn-primary" href="classes.php">Klasse anlegen</a></div></div>'; teacher_footer(); exit; }
$url = teacher_invite_url($class);
$qr = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($url);
$classicRedeemUrl = classroom_base_url() . '/redeem_code.php?code=' . urlencode((string)$class['invite_code']);
teacher_header('Einstellungen', 'Einladung und Klassendaten.');
?>
<div class="row g-4">
  <div class="col-lg-7"><div class="card card-soft"><div class="card-body p-4">
    <h2 class="h5 fw-bold">Klassencode</h2>
    <p class="text-muted">Der Link führt direkt in den Klassenraum. Schüler geben nur ihren Namen ein; ein Schülerkonto bleibt optional.</p>
    <div class="invite-code mb-3"><?= teacher_h($class['invite_code']) ?></div>
    <label class="form-label">Klassenraum-Link für QR-Code / Beamer</label>
    <input class="form-control mb-3" value="<?= teacher_h($url) ?>" readonly>
    <p class="mb-2 small text-muted">Gastbeitritt ist für den Unterricht bewusst niedrigschwellig: Name eingeben, loslegen.</p>
    <label class="form-label mt-3">Klassischer Account-Link</label>
    <input class="form-control" value="<?= teacher_h($classicRedeemUrl) ?>" readonly>
    <p class="mb-0 small text-muted mt-2">Dieser Link ist für Schüler gedacht, die ihren Klassenraum dauerhaft mit einem Konto verknüpfen möchten.</p>
  </div></div></div>
  <div class="col-lg-5"><div class="card card-soft"><div class="card-body p-4 text-center">
    <h2 class="h5 fw-bold">QR-Code</h2>
    <img src="<?= teacher_h($qr) ?>" alt="QR-Code" width="220" height="220" class="rounded-4 border p-2 bg-white">
    <p class="text-muted mt-3 mb-0">Für Beamer, Arbeitsblatt oder Tafel.</p>
  </div></div></div>
</div>
<?php teacher_footer(); ?>
