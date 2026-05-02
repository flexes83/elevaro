# Elevaro MVP

**Claim:** Spielerisch zu guten Noten.

Dies ist die erste PHP-MVP-Basis für Elevaro auf Grundlage des bestehenden Lernquiz-Portals.

## Enthalten

- öffentliche Startseite (`index.php`)
- Quiz-Landingpages mit sprechenden URLs (`/quiz/{slug}/`)
- Spielseiten mit sprechenden URLs (`/spielen/{slug}/`)
- Quiz-Engine mit Moduswahl und Fragenanzahl
- lokale Speicherung per `localStorage`
- Wackelkandidaten-Logik
- Admin-Bereich unter `/admin/`
- Freepik-Bildimport als optionale Admin-Komfortfunktion
- technische Dokumentation unter `/docs/`

## Wichtige Dateien

- `docs/concept.md` – Produktkonzept
- `docs/technical_spec.md` – technische Spezifikation
- `data/quizzes/*.json` – Quiz-Konfigurationen
- `data/quizzes/*.questions.json` – Fragenpools
- `data/quizzes/*.image_prompts.php` – Bild-Prompts für Freepik
- `assets/js/quiz-engine.js` – Quiz-Engine
- `assets/css/portal.css` und `assets/css/quiz.css` – Styling

## Lokaler Start

Einfach in eine PHP-Umgebung legen und im Browser öffnen.

Bei Apache werden die schönen URLs über `.htaccess` unterstützt. Falls die Rewrites nicht aktiv sind, funktionieren die Seiten auch über:

```text
quiz.php?quiz=schwarzwald
play.php?quiz=schwarzwald
```

## Admin-Hinweis

Der Admin ist aktuell bewusst noch nicht geschützt. Vor einer Veröffentlichung muss `/admin/` geschützt werden.

## Medien

Freepik bleibt als optionaler Weg erhalten. Später soll zusätzlich ein manueller Upload ergänzt werden.
