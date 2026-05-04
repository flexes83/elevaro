# Elevaro v11 Signup + Billing + Premium CTA

Auf Basis des letzten Stands integriert:

## Paywall-Texte

- Haupt-CTA: `Premium freischalten`
- Keine 7-Tage-Testphase
- Hinweis: `4,99 € / Monat · monatlich kündbar · Zahlung über Stripe`
- Code-Bereich: `Code einlösen`
- Hinweis: `Login oder Registrierung erfolgt im nächsten Schritt`

## Registrierung

Neue Datei:

- `register.php`

Flow:
- Accountdaten
- Rechnungsdaten
- AGB / Datenschutz Zustimmung
- optional Marketing Consent
- bei Premium: Weiterleitung zu Stripe Checkout
- bei Free/Code: Rückleitung in passenden Flow

## Rechnungsdaten

Neue Felder in `auth_users`:
- billing_name
- billing_email
- billing_address_line1
- billing_address_line2
- billing_postal_code
- billing_city
- billing_country
- billing_tax_id
- accepted_terms_at
- accepted_privacy_at
- marketing_consent_at

SQL:
- `database/schema_signup_billing_v11.sql`

## Stripe

Checkout übergibt:
- billing_address_collection = required
- tax_id_collection enabled
- customer_update address/name auto
- customer_email aus billing_email

Stripe erstellt Rechnungen/Billing weiterhin selbst.

## Codes

Admin-Seite:
- `admin/codes.php`

Kann erstellen:
- Freischaltcodes für 3/6/12 Monate usw.
- Klassencodes mit Lehrer, max. Schüler, Limits

## Dateien hochladen/ersetzen

- `database/schema_signup_billing_v11.sql`
- `app/includes/auth.php`
- `app/includes/stripe_client.php`
- `api/create_checkout_session.php`
- `paywall.php`
- `register.php`
- `redeem_code.php`
- `account.php`
- `assets/css/paywall.css`
- `admin/codes.php`
- `admin/_layout.php`

## Wichtig

Vor Test:
1. SQL ausführen.
2. `/config/stripe.php` mit Testkeys setzen.
3. Stripe Test-Produkt/Preis eintragen.
4. Testen:
   - `/paywall.php`
   - `Premium freischalten`
   - Registrierung
   - Stripe Sandbox Checkout
   - Webhook setzt User auf Premium.
