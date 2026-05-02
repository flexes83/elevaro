# Elevaro – technische Notizen

## Stack

- PHP 8.2+
- JSON-basierte Quizdaten
- Vanilla JavaScript
- CSS ohne Build-Step

## Quiz-Metadaten

Quiz-Konfigurationen können folgende Felder enthalten:

```json
{
  "subject": "Mathe",
  "grade": 5,
  "states": ["BW"],
  "schoolTypes": ["Gymnasium", "Realschule"],
  "curriculumTags": ["Brüche", "Anteile"]
}
```

Diese Metadaten werden für den Quiz-Finder und später für Schülerprofile genutzt.

## Datenstruktur

- `data/quizzes/{slug}.json`: Quiz-Konfiguration
- `data/quizzes/{slug}.questions.json`: Fragenpool
- `assets/quizzes/{slug}/img/`: Medien zum Quiz

## Sicherheit

Admin-Bereich und Konfigurationen sollten vor Livebetrieb geschützt werden. Echte API-Keys und Zugangsdaten gehören nicht ins öffentliche Repository.
