# Elevaro Frontend Header + Role Dashboards

## Dateien hochladen/ersetzen

Neue Dateien:
- `app/includes/frontend_header.php`
- `assets/css/frontend-header.css`
- `assets/css/dashboard.css`
- `dashboard.php`
- `student_dashboard.php`
- `teacher_dashboard.php`
- `account.php`

Angepasste vorhandene Dateien:
- `index.php`
- `onboarding.php`
- `recommendations.php`

## Verhalten

- Im Frontend wird oben ein Login-Button angezeigt, wenn niemand eingeloggt ist.
- Wenn eingeloggt:
  - runder Avatar-Platzhalter mit Initialen
  - Name daneben
  - Dropdown mit:
    - Mein Konto
    - Dashboard
    - Admin-Bereich nur für echte Admins
    - Logout
- Admin bleibt im Admin-Dashboard.
- Lehrer landet im Lehrer-Dashboard.
- Schüler landet im Schüler-Dashboard.
- `dashboard.php` verteilt automatisch nach effektiver Rolle.

## Hinweis

Für das Dropdown wird Bootstrap Bundle eingebunden.
