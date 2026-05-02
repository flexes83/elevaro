<?php
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/openai_client.php';

$pdo = elevaro_db();
$quizId = (int)($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

if (!$quizId) {
    die('quiz_id fehlt.');
}

$stmt = $pdo->prepare("
    SELECT q.*, sub.name AS subject_name
    FROM quizzes q
    LEFT JOIN subjects sub ON sub.id = q.subject_id
    WHERE q.id = :id
    LIMIT 1
");
$stmt->execute(['id' => $quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    die('Quiz nicht gefunden.');
}

$context = [
    'state' => '',
    'school_type' => '',
    'grade' => $quiz['grade'] ?? '',
    'subject' => $quiz['subject_name'] ?? '',
    'topic' => $quiz['title'] ?? '',
    'learning_goal' => '',
    'count' => 15,
];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $context['state'] = trim($_POST['state'] ?? '');
    $context['school_type'] = trim($_POST['school_type'] ?? '');
    $context['grade'] = trim($_POST['grade'] ?? ($quiz['grade'] ?? ''));
    $context['subject'] = trim($_POST['subject'] ?? ($quiz['subject_name'] ?? ''));
    $context['topic'] = trim($_POST['topic'] ?? ($quiz['title'] ?? ''));
    $context['learning_goal'] = trim($_POST['learning_goal'] ?? '');
    $context['count'] = max(5, min(25, (int)($_POST['count'] ?? 15)));

    $prompt = buildPrompt($context);

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'questions' => [
                'type' => 'array',
                'minItems' => $context['count'],
                'maxItems' => $context['count'],
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'options' => [
                            'type' => 'array',
                            'minItems' => 4,
                            'maxItems' => 4,
                            'items' => ['type' => 'string']
                        ],
                        'answer' => ['type' => 'string'],
                        'explanation' => ['type' => 'string'],
                        'difficulty' => [
                            'type' => 'number',
                            'minimum' => 0.05,
                            'maximum' => 0.95
                        ],
                        'difficulty_label' => [
                            'type' => 'string',
                            'enum' => ['leicht','mittel','schwer']
                        ],
                        'common_mistake' => ['type' => 'string']
                    ],
                    'required' => [
                        'question',
                        'options',
                        'answer',
                        'explanation',
                        'difficulty',
                        'difficulty_label',
                        'common_mistake'
                    ]
                ]
            ]
        ],
        'required' => ['questions']
    ];

    try {
        $result = elevaro_openai_chat_json([
            [
                'role' => 'system',
                'content' => 'Du bist ein erfahrener Lehrer und erstellst didaktisch saubere Multiple-Choice-Fragen für Schüler. Du lieferst ausschließlich valide strukturierte JSON-Daten.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ],
        ], $schema, 0.45);

        $generated = $result['json'];

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO ai_generation_logs (quiz_id, prompt, response, status)
            VALUES (:quiz_id, :prompt, :response, 'success')
        ");
        $stmt->execute([
            'quiz_id' => $quizId,
            'prompt' => $prompt,
            'response' => $result['content'],
        ]);

        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM questions WHERE quiz_id = :quiz_id");
        $sortStmt->execute(['quiz_id' => $quizId]);
        $sortOrder = (int)$sortStmt->fetchColumn();

        foreach ($generated['questions'] as $q) {
            $sortOrder++;

            if (!in_array($q['answer'], $q['options'], true)) {
                $q['options'][0] = $q['answer'];
                shuffle($q['options']);
            }

            $stmt = $pdo->prepare("
                INSERT INTO questions
                    (quiz_id, question_key, type, question_text, correct_answer, explanation,
                     difficulty_manual, difficulty_calculated, status, ai_generated, sort_order)
                VALUES
                    (:quiz_id, :question_key, 'mc', :question_text, :correct_answer, :explanation,
                     :difficulty_manual, :difficulty_calculated, 'draft', 1, :sort_order)
            ");

            $stmt->execute([
                'quiz_id' => $quizId,
                'question_key' => slugify($q['question']) . '-' . substr(md5(uniqid('', true)), 0, 6),
                'question_text' => $q['question'],
                'correct_answer' => $q['answer'],
                'explanation' => trim($q['explanation'] . "\n\nTypischer Fehler: " . $q['common_mistake']),
                'difficulty_manual' => $q['difficulty'],
                'difficulty_calculated' => $q['difficulty'],
                'sort_order' => $sortOrder,
            ]);

            $questionId = (int)$pdo->lastInsertId();

            foreach (array_values($q['options']) as $i => $option) {
                $stmt = $pdo->prepare("
                    INSERT INTO question_options
                        (question_id, option_text, is_correct, sort_order)
                    VALUES
                        (:question_id, :option_text, :is_correct, :sort_order)
                ");
                $stmt->execute([
                    'question_id' => $questionId,
                    'option_text' => $option,
                    'is_correct' => $option === $q['answer'] ? 1 : 0,
                    'sort_order' => $i + 1,
                ]);
            }

            $stmt = $pdo->prepare("
                INSERT IGNORE INTO question_stats (question_id, calculated_difficulty)
                VALUES (:question_id, :difficulty)
            ");
            $stmt->execute([
                'question_id' => $questionId,
                'difficulty' => $q['difficulty'],
            ]);
        }

        $pdo->commit();

        header('Location: quiz_questions.php?quiz_id=' . $quizId . '&ai_generated=1');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();

        try {
            $stmt = $pdo->prepare("
                INSERT INTO ai_generation_logs (quiz_id, prompt, response, status, error_message)
                VALUES (:quiz_id, :prompt, NULL, 'failed', :error)
            ");
            $stmt->execute([
                'quiz_id' => $quizId,
                'prompt' => $prompt,
                'error' => $error,
            ]);
        } catch (Throwable $ignored) {}
    }
}

function buildPrompt(array $context): string
{
    return trim("
Erstelle {$context['count']} Multiple-Choice-Fragen für Elevaro.

Rahmen:
- Thema: {$context['topic']}
- Lernziel: {$context['learning_goal']}
- Fach: {$context['subject']}
- Klasse: {$context['grade']}
- Schulart: {$context['school_type']}
- Bundesland: {$context['state']}

Bitte orientiere dich am typischen Lehrplan für dieses Bundesland, diese Schulart und diese Klassenstufe.
Falls du den exakten Lehrplan nicht sicher kennst, bleibe bewusst allgemein schulnah und vermeide Inhalte, die deutlich über das Niveau hinausgehen.

Didaktische Anforderungen:
- altersgerecht formuliert
- typische Schülerfehler aufgreifen
- genau eine richtige Antwort
- exakt 4 Antwortmöglichkeiten
- falsche Antworten plausibel, aber eindeutig falsch
- kurze, motivierende Erklärung
- Mischung: leichte Einstiegsfragen, mittlere Übungsfragen, schwierigere Transferfragen
- Schwierigkeit als Zahl zwischen 0.05 und 0.95
- keine Fangfragen, keine uneindeutigen Lösungen
");
}

function slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    $text = trim($text, '-');
    return mb_substr($text, 0, 120, 'UTF-8') ?: uniqid('q_', true);
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>KI-Fragen generieren – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="../assets/css/design-system.css">
</head>
<body class="bg-light p-4">
  <div class="container" style="max-width: 920px;">
    <a href="quiz_questions.php?quiz_id=<?= (int)$quizId ?>" class="btn btn-light mb-3">← zurück zum Quiz</a>

    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <h1 class="h3 fw-bold">✨ KI-Fragen generieren</h1>
        <p class="text-muted">Die Fragen werden als Entwurf gespeichert und müssen vor Veröffentlichung geprüft werden.</p>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="quiz_id" value="<?= (int)$quizId ?>">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-bold">Bundesland</label>
              <input class="form-control" name="state" value="<?= htmlspecialchars($context['state']) ?>" placeholder="z. B. Baden-Württemberg">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Schulart</label>
              <input class="form-control" name="school_type" value="<?= htmlspecialchars($context['school_type']) ?>" placeholder="z. B. Gymnasium">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">Klasse</label>
              <input class="form-control" name="grade" value="<?= htmlspecialchars((string)$context['grade']) ?>" placeholder="z. B. 5">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">Fach</label>
              <input class="form-control" name="subject" value="<?= htmlspecialchars($context['subject']) ?>" placeholder="z. B. Englisch">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">Anzahl Fragen</label>
              <input class="form-control" type="number" min="5" max="25" name="count" value="<?= (int)$context['count'] ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-bold">Thema</label>
              <input class="form-control" name="topic" value="<?= htmlspecialchars($context['topic']) ?>" placeholder="z. B. this, that, these & those">
            </div>
            <div class="col-12">
              <label class="form-label fw-bold">Lernziel / Hinweise</label>
              <textarea class="form-control" name="learning_goal" rows="3" placeholder="z. B. Schüler sollen unterscheiden können: this/these = nah, that/those = weiter weg; this/that = Einzahl, these/those = Mehrzahl."><?= htmlspecialchars($context['learning_goal']) ?></textarea>
            </div>
          </div>

          <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary btn-lg">✨ Fragen als Entwurf erzeugen</button>
            <a href="quiz_questions.php?quiz_id=<?= (int)$quizId ?>" class="btn btn-light btn-lg">Abbrechen</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
