# Elevaro v10 Freemium Paywall + Stripe-ready

## Ziel

Conversion-optimierter Freemium-Flow mit der Headline:

**Quizz dich zu besseren Noten**

Öffentliche Quizze bleiben spielbar. Premium wird über Ergebnis-CTA, Wiederholungen und Fehlertraining verkauft.

## Dateien hochladen/ersetzen

- `database/schema_freemium_v10.sql`
- `config.example/stripe.php`
- `app/includes/access.php`
- `app/includes/stripe_client.php`
- `app/includes/auth.php`
- `api/create_checkout_session.php`
- `api/stripe_webhook.php`
- `api/quiz_session.php`
- `paywall.php`
- `redeem_code.php`
- `billing_success.php`
- `quiz.php`
- `assets/js/quiz.js`
- `assets/css/quiz.css`
- `assets/css/paywall.css`
- optional `account.php`

## SQL

Bitte `database/schema_freemium_v10.sql` ausführen.
Wenn dein SQL-Tool bei Duplicate Columns stoppt: Zeilen einzeln ausführen und vorhandene überspringen.

## Stripe Config

Auf dem Server anlegen:

`/config/stripe.php`

Inhalt aus `config.example/stripe.php` kopieren und setzen:

- `secret_key`
- `webhook_secret` später für echte Signaturprüfung
- `price_student_monthly` = Stripe Price-ID für 4,99 €/Monat
- `success_url`
- `cancel_url`

## Flow

### Öffentlich / SEO
- Quizze bleiben öffentlich spielbar.
- Gäste können testen.

### Ergebnis-Screen
Nach Quizabschluss:
- Headline `Quizz dich zu besseren Noten`
- Benefits: Fortschritt, Fehlertraining, Serien
- CTA:
  - Gast: Fortschritt speichern / Login
  - Free User: Paywall
  - Premium: Weitere Quizze / Fehlertraining

### Freemium
- eingeloggte Free User: 2 Quizstarts pro Tag
- Wiederholen / Wackelkandidaten führt Free User zur Paywall
- Premium/Admin/Klassencode/Gutscheincode: unbegrenzt

### Codes
- `premium_access_codes`: 3/6/12 Monate kostenlos nutzbar
- `class_codes`: Lehrer-/Klassen-Zugang
- Einlösung über `/redeem_code.php`

## Stripe
- Checkout startet über `/api/create_checkout_session.php`
- Webhook vorbereitet unter `/api/stripe_webhook.php`
- Bei `checkout.session.completed` wird `auth_users.plan = premium` gesetzt

Hinweis:
Webhook-Signaturprüfung ist vorbereitet über Config, aber in diesem MVP bewusst noch nicht hart aktiviert, damit du lokal/auf Testsystem schnell testen kannst.
