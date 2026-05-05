<?php
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/openai_client.php';

$pdo = elevaro_db();
$quizId = (int)($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

if (!$quizId) {
    die('quiz_id fehlt.');
}

$quiz = loadQuizContext($pdo, $quizId);

if (!$quiz) {
    die('Quiz nicht gefunden.');
}

$context = buildContextFromQuiz($quiz);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $context['count'] = max(5, min(25, (int)($_POST['count'] ?? $context['count'])));
    $additionalHint = trim($_POST['learning_goal'] ?? '');

    if ($additionalHint !== '') {
        $context['learning_goal'] = $additionalHint;
    }

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
                'prompt' => $prompt ?? '',
                'error' => $error,
            ]);
        } catch (Throwable $ignored) {}
    }
}

function loadQuizContext(PDO $pdo, int $quizId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            q.*,
            sub.name AS subject_name,
            sub.code AS subject_code,
            lvl.name AS school_type_level_name,
            lvl.code AS school_type_level_code,
            st.name AS school_type_name,
            st.code AS school_type_code,
            s.name AS state_name,
            s.code AS state_code,
            t.title AS topic_title,
            t.learning_goal AS topic_learning_goal,
            t.description AS topic_description
        FROM quizzes q
        LEFT JOIN subjects sub ON sub.id = q.subject_id
        LEFT JOIN school_type_levels lvl ON lvl.id = q.school_type_level_id
        LEFT JOIN quiz_topic_map qtm ON qtm.quiz_id = q.id
        LEFT JOIN curriculum_topics t ON t.id = qtm.topic_id
        LEFT JOIN states s ON s.id = t.state_id
        LEFT JOIN school_types st ON st.id = t.school_type_id
        WHERE q.id = :id
        ORDER BY CASE WHEN t.id IS NULL THEN 1 ELSE 0 END
        LIMIT 1
    ");
    $stmt->execute(['id' => $quizId]);

    $quiz = $stmt->fetch();

    if (!$quiz) {
        return null;
    }

    if (empty($quiz['state_name']) || empty($quiz['school_type_name'])) {
        $fallback = loadQuizContextFallback($pdo, $quiz);
        $quiz = array_merge($quiz, $fallback);
    }

    return $quiz;
}

function loadQuizContextFallback(PDO $pdo, array $quiz): array
{
    $fallback = [
        'state_name' => $quiz['state_name'] ?? '',
        'school_type_name' => $quiz['school_type_name'] ?? '',
        'topic_title' => $quiz['topic_title'] ?? '',
        'topic_learning_goal' => $quiz['topic_learning_goal'] ?? '',
        'topic_description' => $quiz['topic_description'] ?? '',
    ];

    if (!empty($quiz['school_type_level_id'])) {
        $stmt = $pdo->prepare("
            SELECT s.name AS state_name, st.name AS school_type_name
            FROM school_type_levels lvl
            JOIN states s ON s.id = lvl.state_id
            JOIN school_types st ON st.id = lvl.school_type_id
            WHERE lvl.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int)$quiz['school_type_level_id']]);
        $levelContext = $stmt->fetch();

        if ($levelContext) {
            $fallback['state_name'] = $fallback['state_name'] ?: ($levelContext['state_name'] ?? '');
            $fallback['school_type_name'] = $fallback['school_type_name'] ?: ($levelContext['school_type_name'] ?? '');
        }
    }

    return $fallback;
}

function buildContextFromQuiz(array $quiz): array
{
    $levelLabel = trim((string)($quiz['school_type_level_name'] ?? ''));

    if ($levelLabel === '') {
        $grade = (int)($quiz['grade'] ?? 0);
        $levelLabel = $grade > 0 ? ($grade . '. Klasse') : 'nicht angegeben';
    }

    return [
        'state' => trim((string)($quiz['state_name'] ?? '')),
        'school_type' => trim((string)($quiz['school_type_name'] ?? '')),
        'level' => $levelLabel,
        'subject' => trim((string)($quiz['subject_name'] ?? '')),
        'topic' => trim((string)(($quiz['topic_title'] ?? '') ?: ($quiz['title'] ?? ''))),
        'learning_goal' => trim((string)(($quiz['topic_learning_goal'] ?? '') ?: ($quiz['description'] ?? ''))),
        'topic_description' => trim((string)($quiz['topic_description'] ?? '')),
        'count' => 15,
    ];
}

function buildPrompt(array $context): string
{
    return trim("
Erstelle {$context['count']} Multiple-Choice-Fragen für Elevaro.

Rahmen:
- Thema: {$context['topic']}
- Lernziel: {$context['learning_goal']}
- Fach: {$context['subject']}
- Klasse/Stufe: {$context['level']}
- Schulart: {$context['school_type']}
- Bundesland: {$context['state']}

Bitte orientiere dich am bestehenden Kontext dieses Quiz. Die neuen Fragen sollen fachlich, sprachlich und vom Schwierigkeitsgrad zu den bereits bestehenden Fragen passen.

Didaktische Anforderungen:
- alters- und stufengerecht formuliert
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

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
          <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="alert alert-light border mb-4">
          <div class="fw-bold mb-2">Kontext aus bestehendem Quiz</div>
          <div class="row g-2 small">
            <div class="col-md-6"><strong>Bundesland:</strong> <?= h($context['state'] ?: 'nicht angegeben') ?></div>
            <div class="col-md-6"><strong>Schulart:</strong> <?= h($context['school_type'] ?: 'nicht angegeben') ?></div>
            <div class="col-md-6"><strong>Klasse/Stufe:</strong> <?= h($context['level']) ?></div>
            <div class="col-md-6"><strong>Fach:</strong> <?= h($context['subject'] ?: 'nicht angegeben') ?></div>
            <div class="col-12"><strong>Thema:</strong> <?= h($context['topic'] ?: 'nicht angegeben') ?></div>
          </div>
        </div>

        <form method="post">
          <input type="hidden" name="quiz_id" value="<?= (int)$quizId ?>">

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-bold">Anzahl Fragen</label>
              <input class="form-control" type="number" min="5" max="25" name="count" value="<?= (int)$context['count'] ?>">
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Zusätzliche Hinweise / Lernziel</label>
              <textarea class="form-control" name="learning_goal" rows="4" placeholder="Optional: zusätzliche Hinweise für die neuen Fragen."><?= h($context['learning_goal']) ?></textarea>
              <div class="form-text">Bundesland, Schulart, Klasse/Stufe, Fach und Thema werden automatisch aus dem Quiz übernommen.</div>
            </div>
          </div>

          <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary btn-lg">✨ Fragen als Entwurf hinzufügen</button>
            <a href="quiz_questions.php?quiz_id=<?= (int)$quizId ?>" class="btn btn-light btn-lg">Abbrechen</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
