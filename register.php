<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/access.php';
require_once __DIR__ . '/app/includes/frontend_header.php';

auth_start_session();

if (auth_is_logged_in()) {
    header('Location: /account.php');
    exit;
}

$mode = $_GET['mode'] ?? 'free';
$return = $_GET['return'] ?? ($mode === 'premium' ? '/api/create_checkout_session.php' : '/recommendations.php');
$error = null;

$values = [
    'display_name' => '',
    'email' => '',
    'billing_name' => '',
    'billing_address_line1' => '',
    'billing_address_line2' => '',
    'billing_postal_code' => '',
    'billing_city' => '',
    'billing_country' => 'DE',
    'billing_tax_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $_) {
        $values[$key] = trim((string)($_POST[$key] ?? ''));
    }

    try {
        if (empty($_POST['accept_terms']) || empty($_POST['accept_privacy'])) {
            throw new RuntimeException('Bitte AGB und Datenschutz akzeptieren.');
        }

        if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) {
            throw new RuntimeException('Die Passwörter stimmen nicht überein.');
        }

        auth_create_user([
            'email' => $values['email'],
            'password' => (string)($_POST['password'] ?? ''),
            'display_name' => $values['display_name'],
            'role' => 'schueler',
            'billing_name' => $values['billing_name'],
            'billing_email' => $values['email'],
            'billing_address_line1' => $values['billing_address_line1'],
            'billing_address_line2' => $values['billing_address_line2'],
            'billing_postal_code' => $values['billing_postal_code'],
            'billing_city' => $values['billing_city'],
            'billing_country' => $values['billing_country'] ?: 'DE',
            'billing_tax_id' => $values['billing_tax_id'],
            'marketing_consent' => !empty($_POST['marketing_consent']),
        ]);

        header('Location: ' . $return);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function rv(string $key, array $values): string {
    return htmlspecialchars((string)($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Registrieren – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Erstelle deinen Elevaro Account und lerne mit passenden Quizzen weiter.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/paywall.css">
  <link rel="stylesheet" href="/assets/css/frontend-header.css">
</head>
<body>
<?php elevaro_frontend_header('light', []); ?>

<main class="paywall-page">
  <section class="signup-shell">
    <div class="signup-card">
      <span class="paywall-kicker"><?= $mode === 'premium' ? 'Elevaro Premium' : 'Elevaro Account' ?></span>
      <h1><?= $mode === 'premium' ? 'Premium freischalten' : 'Kostenlosen Account erstellen' ?></h1>
      <p class="paywall-subline">
        <?= $mode === 'premium'
          ? 'Erstelle zuerst deinen Account. Danach geht es direkt zum sicheren Stripe-Checkout.'
          : 'Speichere deinen Fortschritt und finde passende Quizze schneller wieder.' ?>
      </p>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" class="signup-form">
        <div class="signup-section">
          <h2>Account</h2>
          <div class="row g-3">
            <div class="col-md-6">
              <label>Dein Name</label>
              <input name="display_name" value="<?= rv('display_name', $values) ?>" placeholder="z. B. Felix" required>
            </div>
            <div class="col-md-6">
              <label>E-Mail</label>
              <input type="email" name="email" value="<?= rv('email', $values) ?>" placeholder="du@example.de" required>
            </div>
            <div class="col-md-6">
              <label>Passwort</label>
              <input type="password" name="password" minlength="8" required>
            </div>
            <div class="col-md-6">
              <label>Passwort wiederholen</label>
              <input type="password" name="password_confirm" minlength="8" required>
            </div>
          </div>
        </div>

        <div class="signup-section">
          <h2>Rechnungsdaten</h2>
          <p>Diese Daten werden an Stripe übergeben und für Rechnungen verwendet.</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label>Name für Rechnung</label>
              <input name="billing_name" value="<?= rv('billing_name', $values) ?>" placeholder="Name / ggf. Erziehungsberechtigte">
            </div>
            <div class="col-md-6">
              <label>Land</label>
              <select name="billing_country">
                <option value="DE" <?= $values['billing_country']==='DE'?'selected':'' ?>>Deutschland</option>
                <option value="AT" <?= $values['billing_country']==='AT'?'selected':'' ?>>Österreich</option>
                <option value="CH" <?= $values['billing_country']==='CH'?'selected':'' ?>>Schweiz</option>
              </select>
            </div>
            <div class="col-md-8">
              <label>Straße und Hausnummer</label>
              <input name="billing_address_line1" value="<?= rv('billing_address_line1', $values) ?>">
            </div>
            <div class="col-md-4">
              <label>Adresszusatz</label>
              <input name="billing_address_line2" value="<?= rv('billing_address_line2', $values) ?>">
            </div>
            <div class="col-md-4">
              <label>PLZ</label>
              <input name="billing_postal_code" value="<?= rv('billing_postal_code', $values) ?>">
            </div>
            <div class="col-md-8">
              <label>Ort</label>
              <input name="billing_city" value="<?= rv('billing_city', $values) ?>">
            </div>
            <div class="col-12">
              <label>USt-ID / Steuernummer optional</label>
              <input name="billing_tax_id" value="<?= rv('billing_tax_id', $values) ?>">
            </div>
          </div>
        </div>

        <div class="signup-checks">
          <label><input type="checkbox" name="accept_terms" required> Ich akzeptiere die AGB.</label>
          <label><input type="checkbox" name="accept_privacy" required> Ich habe die Datenschutzhinweise gelesen.</label>
          <label><input type="checkbox" name="marketing_consent"> Ich möchte gelegentlich Produktinfos erhalten.</label>
        </div>

        <button class="btn btn-primary btn-lg"><?= $mode === 'premium' ? 'Weiter zum sicheren Checkout' : 'Account erstellen' ?></button>

        <p class="signup-login-hint">
          Du hast schon einen Account?
          <a href="/login.php?return=<?= urlencode($return) ?>">Einloggen</a>
        </p>
      
</form>

<div class="register-skip">
  <a href="/recommendations.php">Ohne Anmeldung fortfahren</a>
  <div class="register-warning">
    Dein Fortschritt und deine Ergebnisse gehen verloren.
  </div>
</div>

    </div>
  </section>
</main>
</body>
</html>
