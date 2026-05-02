# Lernquiz Portal – verbesserter Start

## Enthalten

- öffentliche Portal-Startseite (`index.php`)
- SEO-Startseiten pro Quiz mit sprechenden URLs (`/quiz/pferde/`)
- Spielseiten mit sprechenden URLs (`/spielen/pferde/`)
- bessere Quiz-Oberfläche mit Moduswahl und Fragenanzahl
- lokale Speicherung: Name, Avatar, Highscore, Wackelkandidaten, angefangene Runde
- Runden-Review: falsche Antworten aus der aktuellen Runde wiederholen
- Mail-Auswertung über `report.php` und `mail_config.php`
- Backend unter `/admin/`
- moderierter Freepik-Bildimport unter `/admin/images.php?quiz=pferde`

## Wichtige Dateien

- `data/quizzes/*.json` – Quiz-Konfiguration: Farben, Icons, SEO, Modi, Cover, Fragenanzahlen
- `data/quizzes/*.questions.json` – Fragenpool
- `assets/quizzes/<slug>/img/` – lokale Bilder pro Quiz
- `config/freepik.php` – API-Key eintragen
- `mail_config.php` – Empfängeradresse für Reports

## Freepik-Bilder

Im Backend sind passende Suchanfragen pro Quiz bereits vorausgefüllt.
Nach dem Import werden Bilder lokal gespeichert und können dann in der Quiz-Konfiguration oder in Fragen verwendet werden.

Für Quizbilder unbedingt prüfen:

- kein Text im Bild
- kein Logo/Wasserzeichen
- Motiv eindeutig
- bei Rassen: wirklich passende Rasse

## Hinweis

Das Backend ist bewusst noch ohne Passwortschutz. Vor Veröffentlichung bitte per `.htaccess`, Server-Verzeichnisschutz oder Login absichern.

## Bild-Prompts pro Quiz

Das Freepik-Backend liest die Such-Prompts nun pro Quiz aus:

- `data/quizzes/voegel.image_prompts.php`
- `data/quizzes/pferde.image_prompts.php`
- `data/quizzes/schwarzwald.image_prompts.php`

Im Backend unter `/admin/images.php?quiz=pferde` wird beim Wechsel des lokalen Dateinamens automatisch der passende Prompt vorausgefüllt. Beim Import wird zusätzlich eine kleine Historie unter `data/image_imports/{quiz}.json` gespeichert.
