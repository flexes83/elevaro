# Quiz-Datenformat für das Lernquiz-Portal

Diese Datei beschreibt das Format, damit neue Quizze später konsistent erstellt werden können.

## Dateinamen pro Quiz

Für ein Quiz mit dem Slug `pferde` liegen die Dateien im Ordner:

```text
data/quizzes/
  pferde.json
  pferde.questions.json
  pferde.image_prompts.php
```

Für ein neues Quiz also immer:

```text
{slug}.json
{slug}.questions.json
{slug}.image_prompts.php
```

Beispiele:

```text
voegel.json
voegel.questions.json
voegel.image_prompts.php

schwarzwald.json
schwarzwald.questions.json
schwarzwald.image_prompts.php
```

Der `slug` sollte klein geschrieben sein, keine Leerzeichen enthalten und für URLs geeignet sein.

## Quiz-Konfiguration `{slug}.json`

Pflicht / wichtig:

```json
{
  "order": 1,
  "slug": "beispiel",
  "title": "Beispiel-Quiz",
  "shortTitle": "Beispiel",
  "category": "Kategorie",
  "icon": "⭐",
  "color": "#2f7d55",
  "softColor": "#e9f6ee",
  "accent": "#ffe08a",
  "permalink": "beispiel",
  "coverImage": "assets/quizzes/beispiel/img/cover.svg",
  "cardImages": [
    "assets/quizzes/beispiel/img/cover.svg"
  ],
  "description": "Kurzer Beschreibungstext.",
  "longDescription": "Längerer SEO-/Startseiten-Text.",
  "seoTitle": "SEO Title",
  "seoDescription": "SEO Description",
  "imageBase": "assets/quizzes/beispiel/img/",
  "roundLength": 10,
  "questionCounts": [5, 10, 15, 20],
  "mailReports": true,
  "avatars": ["⭐", "🎯"],
  "tags": ["Tag 1", "Tag 2"],
  "learningGoals": ["Lernziel 1", "Lernziel 2"],
  "modes": []
}
```

## Fragen-Datei `{slug}.questions.json`

Die Fragen-Datei ist ein JSON-Array.

### Wichtig

- Die richtige Antwort steht immer als String im Feld `answer`.
- `answer` muss exakt in `options` vorkommen.
- Erklärung/Infotext heißt `fact`.
- Kein Feld `correct` verwenden.
- Kein Feld `explanation` verwenden.
- Bilddateien werden nur mit Dateiname angegeben, z. B. `"feldberg.jpg"`.
- Der vollständige Bildpfad wird über `imageBase` aus der Quiz-Konfiguration gebildet.

### Multiple Choice

```json
{
  "id": "beispiel_001",
  "type": "mc",
  "category": "Kategorie",
  "question": "Frage?",
  "options": ["Antwort A", "Antwort B", "Antwort C", "Antwort D"],
  "answer": "Antwort B",
  "fact": "Kurze Erklärung."
}
```

### Bildfrage mit Antwortbuttons

Der bestehende Renderer nutzt `image_choice`: ein Bild wird angezeigt, darunter stehen Antwortbuttons.

```json
{
  "id": "beispiel_bild_001",
  "type": "image_choice",
  "category": "Bilder",
  "question": "Was sieht man auf dem Bild?",
  "image": "bildname.jpg",
  "options": ["A", "B", "C", "D"],
  "answer": "B",
  "fact": "Kurze Erklärung."
}
```

### Wahr/Falsch

Als normale MC-Frage anlegen, damit das Format einheitlich bleibt:

```json
{
  "id": "beispiel_tf_001",
  "type": "mc",
  "category": "Wissen",
  "question": "Diese Aussage ist richtig?",
  "options": ["Wahr", "Falsch"],
  "answer": "Wahr",
  "fact": "Kurze Erklärung."
}
```

## Bild-Prompt-Datei `{slug}.image_prompts.php`

Diese Datei füllt im Admin die Freepik-Suche vor.

```php
<?php
return [
  'cover.jpg' => [
    'label' => 'Cover',
    'query' => 'educational cover image no text no logo no watermark'
  ],
  'feldberg.jpg' => [
    'label' => 'Feldberg',
    'query' => 'Feldberg Black Forest Germany mountain landscape photo no text no logo no watermark'
  ]
];
```

## Bilddateien

Öffentliche Assets liegen passend zum Quiz-Slug unter:

```text
assets/quizzes/{slug}/img/
```

Beispiel:

```text
assets/quizzes/schwarzwald/img/feldberg.jpg
assets/quizzes/schwarzwald/img/luv_lee_schema_clean.svg
```

In der JSON steht nur:

```json
"image": "luv_lee_schema_clean.svg"
```

## Validierungsregeln

Vor dem Einbau prüfen:

1. Jede Frage hat `id`, `type`, `category`, `question`, `options`, `answer`, `fact`.
2. `answer` kommt exakt in `options` vor.
3. `id` ist eindeutig.
4. Für `image_choice` ist `image` gesetzt.
5. Die Bilddatei existiert im passenden Asset-Ordner oder ist im Backend importierbar.
