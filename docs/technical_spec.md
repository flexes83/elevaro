# Elevaro – Technical Specification (MVP)

## 1. Overview

Elevaro is a PHP-based quiz platform focused on school-related learning content. The MVP uses JSON files for quiz content and localStorage for progress tracking.

The architecture is intentionally simple and designed to be extended later with accounts, teacher classes, premium access and a database-backed content system.

## 2. Tech Stack

### Backend

- PHP 8+
- vanilla PHP, no framework
- Apache `.htaccess` rewrites for pretty URLs

### Frontend

- HTML5
- CSS
- Vanilla JavaScript
- Fetch/AJAX

### Storage in MVP

- JSON files for quiz config and questions
- localStorage for name, avatar, stats, highscore, active round and weak questions

### Planned future storage

- MySQL/MariaDB
- session-based auth
- user progress tables
- teacher class tables

## 3. Current Project Structure

```text
elevaro/
  index.php
  quiz.php
  play.php
  report.php
  mail_config.php
  .htaccess

  lib/
    functions.php

  admin/
    index.php
    quiz.php
    images.php
    api/
      freepik_search.php
      freepik_import.php

  config/
    freepik.php

  assets/
    css/
      portal.css
      quiz.css
    js/
      quiz-engine.js
    img/
      placeholder.svg
    quizzes/
      {slug}/img/

  data/
    quizzes/
      {slug}.json
      {slug}.questions.json
      {slug}.image_prompts.php
      template-fuer-ki/
    image_imports/

  docs/
    concept.md
    technical_spec.md
```

## 4. Routing

Pretty URLs are handled in `.htaccess`.

```apache
RewriteRule ^quiz/([a-z0-9_-]+)/?$ quiz.php?quiz=$1 [L,QSA]
RewriteRule ^spielen/([a-z0-9_-]+)/?$ play.php?quiz=$1 [L,QSA]
```

Routes:

| Route | Purpose |
|---|---|
| `/` | Quiz overview |
| `/quiz/{slug}/` | Quiz landing page |
| `/spielen/{slug}/` | Quiz gameplay |
| `/admin/` | Admin overview |
| `/admin/quiz.php?quiz={slug}` | Quiz config editor |
| `/admin/images.php?quiz={slug}` | Image/Freepik management |

## 5. Quiz Config Format

File:

```text
data/quizzes/{slug}.json
```

Important fields:

```json
{
  "order": 1,
  "slug": "mathe-klasse-5-bruchrechnen",
  "title": "Bruchrechnen – Klasse 5",
  "shortTitle": "Bruchrechnen",
  "category": "Mathe Klasse 5",
  "icon": "➗",
  "color": "#4f46e5",
  "softColor": "#eef2ff",
  "coverImage": "assets/quizzes/mathe-klasse-5-bruchrechnen/img/cover.svg",
  "description": "Kurzer Beschreibungstext.",
  "longDescription": "Längerer SEO- und Landingpage-Text.",
  "seoTitle": "SEO Title",
  "seoDescription": "SEO Description",
  "imageBase": "assets/quizzes/mathe-klasse-5-bruchrechnen/img/",
  "roundLength": 10,
  "questionCounts": [5, 10, 15, 20],
  "avatars": ["🐼", "⭐", "🧠"],
  "tags": ["Mathe", "Klasse 5"],
  "learningGoals": ["Lernziel 1", "Lernziel 2"],
  "modes": []
}
```

## 6. Question Format

File:

```text
data/quizzes/{slug}.questions.json
```

Multiple Choice:

```json
{
  "id": "bruch_001",
  "type": "mc",
  "category": "Brüche verstehen",
  "question": "Was ist die Hälfte von 10?",
  "options": ["2", "5", "10", "20"],
  "answer": "5",
  "fact": "Die Hälfte von 10 ist 5."
}
```

Rules:

- `answer` must be a string
- `answer` must exactly match one value in `options`
- explanation text is stored in `fact`
- do not use `correct`
- do not use `explanation`
- question IDs must be unique per quiz

## 7. Quiz Engine

Main file:

```text
assets/js/quiz-engine.js
```

Responsibilities:

- load questions by quiz slug
- normalize questions
- render start screen
- handle mode selection
- pick round questions
- render current question
- evaluate answer
- show feedback
- store stats
- store active round
- render result screen
- replay false answers from the current round

## 8. LocalStorage Keys

Current storage key:

```text
lernquiz_{slug}
```

For Elevaro this can later be migrated to:

```text
elevaro_{slug}
```

Stored data includes:

- name
- avatar
- highscore
- best streak
- per-question stats
- active round

## 9. Wackelkandidaten Logic

Each question receives a local `weight` value.

- wrong answer: `weight += 2`
- right answer: `weight -= 1`, minimum 0

The weak mode prioritizes questions where `weight > 0`.

## 10. Admin System

The admin area currently has no login protection. Before publication, protect `/admin/` via:

- `.htaccess` directory protection
- or a proper login system

The Freepik image flow remains part of the admin, but it is optional. Future admin media handling should support:

- Freepik search/import
- manual upload
- existing local images
- no-image fallback

## 11. Future Database Model

Planned tables:

```text
users
quizzes
questions
quiz_attempts
question_stats
classes
class_members
class_quizzes
teacher_quizzes
referrals
subscriptions
```

## 12. Important Next Development Steps

1. rebrand current interface to Elevaro
2. add one real school-content quiz
3. rename localStorage key carefully or keep compatibility
4. add optional mail report switch or remove reports from MVP
5. protect admin before public deployment
6. add manual image upload to admin
7. prepare database migration plan
```
