<?php

declare(strict_types=1);

require_once __DIR__ . '/app/includes/db.php';
require_once __DIR__ . '/app/includes/auth.php';

function shared_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function shared_abs(string $path): string { return '/' . ltrim($path, '/'); }
function shared_type_label(string $type): string { return match ($type) { 'worksheet' => 'Arbeitsblatt', 'listening' => 'Listening', 'reading' => 'Leseverständnis', default => 'Quiz' }; }
function shared_type_icon(string $type): string { return match ($type) { 'worksheet' => '📄', 'listening' => '🎧', 'reading' => '📖', default => '🎮' }; }

function shared_item_from_ref(PDO $pdo, string $ref): ?array
{
    if (!preg_match('/^(quiz|worksheet|listening|reading):(\d+)$/', trim($ref), $m)) return null;
    $type = $m[1];
    $id = (int)$m[2];

    if ($type === 'worksheet') {
        $stmt = $pdo->prepare('SELECT id, title, description FROM teacher_custom_quizzes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return ['type' => 'worksheet', 'title' => (string)$row['title'], 'description' => (string)($row['description'] ?? ''), 'url' => '/teacher/material_pdf.php?custom_quiz_id=' . (int)$row['id']];
    }

    $stmt = $pdo->prepare('SELECT q.id, q.quiz_key, q.title, q.description, q.image_path, q.theme_emoji, q.listening_mode, COUNT(qq.id) AS question_count FROM quizzes q LEFT JOIN questions qq ON qq.quiz_id = q.id WHERE q.id = :id GROUP BY q.id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $resolvedType = ((int)($row['listening_mode'] ?? 0) === 1 || $type === 'listening') ? 'listening' : 'quiz';
    return ['type' => $resolvedType, 'title' => (string)$row['title'], 'description' => (string)($row['description'] ?? ''), 'question_count' => (int)($row['question_count'] ?? 0), 'image_path' => (string)($row['image_path'] ?? ''), 'emoji' => (string)($row['theme_emoji'] ?? ''), 'url' => !empty($row['quiz_key']) ? '/quiz.php?key=' . urlencode((string)$row['quiz_key']) : ''];
}

$pdo = elevaro_db();
$token = trim((string)($_GET['token'] ?? ''));
$user = auth_user();
$error = '';
$notice = '';
$share = null;
$unit = null;
$items = [];
$guestExpired = false;

if ($token === '') {
    $error = 'Dieser Freigabelink ist ungültig.';
} else {
    $stmt = $pdo->prepare("SELECT s.*, u.title, u.description, u.subject_label, u.grade, u.curriculum_topic_label, u.curriculum_subtopic_label, au.display_name AS owner_name, au.email AS owner_email
        FROM teacher_unit_colleague_shares s
        JOIN teacher_units u ON u.id = s.unit_id
        LEFT JOIN auth_users au ON au.id = s.teacher_id
        WHERE s.token = :token LIMIT 1");
    $stmt->execute(['token' => $token]);
    $share = $stmt->fetch();
    if (!$share) {
        $error = 'Diese Freigabe wurde nicht gefunden.';
    } else {
        $unit = $share;
        $guestExpires = trim((string)($share['guest_expires_at'] ?? ''));
        $guestExpired = $guestExpires !== '' && strtotime($guestExpires) !== false && strtotime($guestExpires) < time();
        $refs = json_decode((string)($share['item_refs_json'] ?? '[]'), true);
        $refs = is_array($refs) ? $refs : [];
        foreach ($refs as $ref) {
            $item = shared_item_from_ref($pdo, (string)$ref);
            if ($item) $items[] = $item;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'accept_share') {
            if (!$user || (string)($user['role'] ?? '') !== 'lehrer') {
                header('Location: /login.php?share_token=' . urlencode($token));
                exit;
            }
            $accept = $pdo->prepare('UPDATE teacher_unit_colleague_shares SET accepted_by_user_id = :user_id, accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP) WHERE token = :token LIMIT 1');
            $accept->execute(['user_id' => (int)$user['id'], 'token' => $token]);
            header('Location: /teacher/materials.php?view=shared');
            exit;
        }
    }
}

$meta = '';
if ($unit) {
    $meta = trim((string)($unit['subject_label'] ?? '') . (!empty($unit['grade']) ? ' · Klasse ' . (string)$unit['grade'] : '') . (!empty($unit['curriculum_subtopic_label'] ?: $unit['curriculum_topic_label']) ? ' · ' . (string)(($unit['curriculum_subtopic_label'] ?? '') ?: ($unit['curriculum_topic_label'] ?? '')) : ''));
}
$cover = '';
$emoji = '🧩';
foreach ($items as $item) {
    if ($cover === '' && !empty($item['image_path'])) $cover = (string)$item['image_path'];
    if ($emoji === '🧩' && !empty($item['emoji'])) $emoji = (string)$item['emoji'];
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Geteilte Elevaro-Unit</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{--ev:#5a4ff3;--dark:#172033;--muted:#64748b}body{margin:0;min-height:100vh;background:linear-gradient(135deg,#f7f6ff 0%,#eef2ff 48%,#fff 100%);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--dark)}.share-shell{max-width:1040px;margin:0 auto;padding:38px 18px 70px}.brand{font-weight:950;color:var(--ev);font-size:1.35rem;letter-spacing:-.04em;margin-bottom:24px}.hero{overflow:hidden;border-radius:34px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 28px 90px rgba(23,32,51,.11)}.hero-cover{min-height:250px;background:linear-gradient(135deg,#5a4ff3,#8b7cff 55%,#22d3ee);background-size:cover;background-position:center;position:relative}.hero-cover::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(23,32,51,0) 25%,rgba(23,32,51,.58) 100%)}.hero-symbol{position:absolute;left:28px;bottom:24px;z-index:1;width:68px;height:68px;border-radius:24px;background:rgba(255,255,255,.94);display:flex;align-items:center;justify-content:center;font-size:2rem;box-shadow:0 18px 42px rgba(23,32,51,.18)}.hero-body{padding:28px}.kicker{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ev);font-weight:950;margin-bottom:8px}.hero h1{font-size:clamp(2rem,5vw,3.5rem);line-height:.96;font-weight:950;letter-spacing:-.055em;margin:0 0 12px}.meta{color:var(--muted);font-weight:800}.shared-by{margin-top:14px;display:inline-flex;gap:8px;align-items:center;padding:8px 11px;border-radius:999px;background:#f8fafc;color:#64748b;font-weight:800}.items{display:grid;gap:10px;margin-top:24px}.item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px;border-radius:20px;background:#f8fafc;border:1px solid rgba(23,32,51,.07)}.icon{width:44px;height:44px;border-radius:16px;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 20px rgba(23,32,51,.06)}.item strong{display:block}.item small{color:#64748b;font-weight:750}.cta-panel{margin-top:24px;padding:22px;border-radius:26px;background:#172033;color:#fff}.cta-panel h2{font-weight:950;font-size:1.3rem;margin:0 0 8px}.cta-panel p{color:rgba(255,255,255,.72);margin:0 0 18px}.usp{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:18px 0}.usp div{border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);border-radius:18px;padding:12px;font-weight:800}.links{display:flex;gap:14px;flex-wrap:wrap;align-items:center}.links a{color:#fff;font-weight:850}.guest-note{font-size:.9rem;color:#64748b;margin-top:12px}.expired{padding:16px;border-radius:20px;background:#fff7ed;color:#9a3412;font-weight:800;margin-top:18px}@media(max-width:760px){.item{grid-template-columns:auto minmax(0,1fr)}.item .btn{grid-column:1/-1}.usp{grid-template-columns:1fr}.hero-body{padding:22px}}
  </style>
</head>
<body>
<div class="share-shell">
  <div class="brand">Elevaro</div>
  <?php if ($error): ?>
    <div class="alert alert-danger rounded-4"><?= shared_h($error) ?></div>
  <?php else: ?>
    <section class="hero">
      <div class="hero-cover" <?= $cover !== '' ? 'style="background-image:url(' . shared_h(shared_abs($cover)) . ')"' : '' ?>><div class="hero-symbol"><?= shared_h($emoji) ?></div></div>
      <div class="hero-body">
        <div class="kicker">Geteilte Unit</div>
        <h1><?= shared_h((string)($unit['title'] ?? 'Unit')) ?></h1>
        <?php if ($meta !== ''): ?><div class="meta"><?= shared_h($meta) ?></div><?php endif; ?>
        <div class="shared-by">🤝 geteilt von <?= shared_h((string)(($unit['owner_name'] ?? '') ?: ($unit['owner_email'] ?? 'Kolleg:in'))) ?></div>

        <?php if (!$items): ?>
          <p class="text-muted mt-4 mb-0">Keine Inhalte in dieser Freigabe gefunden.</p>
        <?php else: ?>
          <div class="items">
            <?php foreach ($items as $item): ?>
              <div class="item">
                <span class="icon"><?= shared_type_icon((string)$item['type']) ?></span>
                <span><strong><?= shared_h((string)$item['title']) ?></strong><small><?= shared_h(shared_type_label((string)$item['type'])) ?><?= !empty($item['question_count']) ? ' · ' . (int)$item['question_count'] . ' Fragen' : '' ?></small></span>
                <?php if (!$guestExpired && !empty($item['url'])): ?><a class="btn btn-primary btn-sm rounded-pill fw-bold" href="<?= shared_h((string)$item['url']) ?>">Öffnen</a><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($guestExpired): ?>
          <div class="expired">Die 24-Stunden-Vorschau ist abgelaufen. Melde dich an oder erstelle einen kostenlosen Account, um freigegebene Inhalte dauerhaft zu speichern.</div>
        <?php endif; ?>

        <div class="cta-panel">
          <h2>Speichere die Unit dauerhaft in deiner Elevaro-Bibliothek.</h2>
          <p>Kostenlos registrieren, geteilte Inhalte sammeln und später bequem im Unterricht nutzen.</p>
          <div class="usp"><div>✓ Inhalte zentral sammeln</div><div>✓ Vorschau & Unterrichtsnutzung</div><div>✓ Später eigene Klassen freischalten</div></div>
          <form method="post" class="links">
            <input type="hidden" name="action" value="accept_share">
            <?php if ($user && (string)($user['role'] ?? '') === 'lehrer'): ?>
              <button class="btn btn-light rounded-pill fw-bold" type="submit">In meiner Bibliothek speichern</button>
            <?php else: ?>
              <a class="btn btn-light rounded-pill fw-bold" href="/teacher_register.php?share_token=<?= urlencode($token) ?>">Kostenlos registrieren</a>
              <a href="/login.php?share_token=<?= urlencode($token) ?>">Ich habe bereits einen Account</a>
            <?php endif; ?>
          </form>
          <?php if (!$guestExpired): ?><div class="guest-note">Du kannst die Inhalte 24 Stunden über diesen Link ansehen. Danach brauchst du einen kostenlosen Account.</div><?php endif; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>
</div>
</body>
</html>
