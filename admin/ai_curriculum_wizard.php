<?php
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/openai_client.php';

$pdo = elevaro_db();

$action = $_POST['action'] ?? $_GET['action'] ?? 'start';
$error = null;
$generatedTopics = null;

$states = $pdo->query("SELECT id, code, name FROM states ORDER BY sort_order, name")->fetchAll();
$schoolTypes = $pdo->query("SELECT id, code, name FROM school_types ORDER BY sort_order, name")->fetchAll();
$subjects = $pdo->query("SELECT id, code, name, icon FROM subjects ORDER BY sort_order, name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'generate_topics') {
            $context = collectContext($_POST, $states, $schoolTypes, $subjects);
            $generatedTopics = generateTopics($context);
        }

        if ($action === 'save_topic_only') {
            $topicId = saveCurriculumTopic($pdo, $_POST);
            header('Location: ai_curriculum_wizard.php?saved_topic=' . (int)$topicId);
            exit;
        }

        if ($action === 'create_quiz_and_questions') {
            $createdQuiz = createQuizFromPost($pdo, $_POST);
            $generatedQuestions = generateQuestionsForQuiz($createdQuiz);
            saveQuestionsAsDraft($pdo, (int)$createdQuiz['quiz_id'], $generatedQuestions['questions']);
            header('Location: quiz_questions.php?quiz_id=' . (int)$createdQuiz['quiz_id'] . '&ai_generated=1');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function collectContext(array $post, array $states, array $schoolTypes, array $subjects): array
{
    $state = findById($states, (int)($post['state_id'] ?? 0));
    $schoolType = findById($schoolTypes, (int)($post['school_type_id'] ?? 0));
    $subject = findById($subjects, (int)($post['subject_id'] ?? 0));
    $grade = (int)($post['grade'] ?? 0);

    if (!$state || !$schoolType || !$subject || !$grade) {
        throw new RuntimeException('Bitte Bundesland, Schulart, Klasse und Fach auswählen.');
    }

    return [
        'state_id' => (int)$state['id'],
        'state_code' => $state['code'],
        'state_name' => $state['name'],
        'school_type_id' => (int)$schoolType['id'],
        'school_type_code' => $schoolType['code'],
        'school_type_name' => $schoolType['name'],
        'subject_id' => (int)$subject['id'],
        'subject_code' => $subject['code'],
        'subject_name' => $subject['name'],
        'subject_icon' => $subject['icon'] ?? '',
        'grade' => $grade,
        'focus' => trim($post['focus'] ?? ''),
        'official_sources' => trim($post['official_sources'] ?? ''),
        'source_notes' => trim($post['source_notes'] ?? ''),
    ];
}

function generateTopics(array $context): array
{
    $prompt = buildTopicPrompt($context);

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'topics' => [
                'type' => 'array',
                'minItems' => 6,
                'maxItems' => 10,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'code' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'learning_goal' => ['type' => 'string'],
                        'why_relevant' => ['type' => 'string'],
                        'curriculum_reference' => ['type' => 'string'],
                        'quiz_ideas' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'maxItems' => 4,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'learning_goal' => ['type' => 'string'],
                                    'difficulty_mix' => ['type' => 'string']
                                ],
                                'required' => ['title','description','learning_goal','difficulty_mix']
                            ]
                        ]
                    ],
                    'required' => ['title','code','description','learning_goal','why_relevant','curriculum_reference','quiz_ideas']
                ]
            ]
        ],
        'required' => ['topics']
    ];

    $result = elevaro_openai_chat_json([
        [
            'role' => 'system',
            'content' => 'Du bist ein erfahrener deutscher Lehrer und Curriculum-Experte. Du strukturierst schulnahe Lernquizze. Du lieferst ausschließlich valide JSON-Daten. Wenn offizielle Quellen/Notizen mitgegeben werden, priorisierst du diese vor allgemeinem Wissen.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ],
    ], $schema, 0.30);

    return [
        'context' => $context,
        'topics' => $result['json']['topics'],
        'prompt' => $prompt,
        'raw' => $result['content'],
    ];
}

function buildTopicPrompt(array $context): string
{
    $focus = $context['focus']
        ? "- Zusätzlicher Schwerpunkt/Hinweis: {$context['focus']}"
        : "- Zusätzlicher Schwerpunkt/Hinweis: keiner";

    $sources = $context['official_sources']
        ? "- Offizielle Quellen/Links:\n" . indentLines($context['official_sources'])
        : "- Offizielle Quellen/Links: keine angegeben";

    $sourceNotes = $context['source_notes']
        ? "- Aus Quellen übernommene Stichpunkte/Kompetenzen:\n" . indentLines($context['source_notes'])
        : "- Aus Quellen übernommene Stichpunkte/Kompetenzen: keine angegeben";

    return trim("
Erstelle eine Liste sinnvoller, lehrplannaher Themen für Lernquizze in Elevaro.

Kontext:
- Bundesland: {$context['state_name']}
- Schulart: {$context['school_type_name']}
- Klassenstufe: {$context['grade']}
- Fach: {$context['subject_name']}
{$focus}

Quellenkontext:
{$sources}
{$sourceNotes}

WICHTIG ZU DEN QUELLEN:
- Wenn offizielle Quellen oder Stichpunkte angegeben sind, müssen diese die wichtigste Grundlage sein.
- Du kannst die Links selbst nicht live öffnen. Verwende daher besonders die mitgegebenen Stichpunkte/Kompetenzen.
- Erfinde keine exakten Bildungsplan-Zitate.
- Formuliere nicht: „steht exakt im Bildungsplan“, außer es wurde als Stichpunkt mitgegeben.
- Wenn der exakte Bildungsplan unklar ist, bleibe realistisch schulnah und kennzeichne den Bezug allgemein als „typischer Kompetenzbereich“.

Ziel:
Die Themen sollen für kurze, motivierende Lernquizze geeignet sein und sich am typischen Bildungsplan bzw. Lehrplan des angegebenen Bundeslands, der Schulart, Klassenstufe und des Fachs orientieren.

Didaktische Anforderungen:
- altersgerecht für die angegebene Klassenstufe
- passend zur angegebenen Schulart
- vom Einfachen zum Schwierigen aufgebaut
- nicht nur reines Auswendiglernen
- Fokus auf Verstehen, Anwenden, Erkennen, Zuordnen, Vergleichen, Begründen und Einordnen
- typische Schülerfehler oder Missverständnisse berücksichtigen
- gut in kurzen Quiz-Sessions abbildbar
- keine Inhalte deutlich über der Klassenstufe
- keine Prüfungsversprechen

Fachspezifische Orientierung:
- In Sprachen: Wortschatz, Grammatik, Sprachverständnis, Anwendung in Beispielsätzen
- In Mathematik: Begriffe, Rechenwege, Muster, typische Fehler, einfache Anwendungen
- In Naturwissenschaften: Beobachten, Beschreiben, Zusammenhänge, einfache Modelle
- In Gesellschafts-/Geofächern: Orientierung, Begriffe, Zusammenhänge, Karten/Quellen/Bilder verstehen
- In Deutsch: Sprachgefühl, Grammatik, Lesen, Textverständnis, Schreiben
- In Sach-/Grundschulfächern: Alltagsbezug, Beobachten, Zuordnen, Grundbegriffe

Liefere 6 bis 10 Themen.

Jedes Thema enthält:
- title: kurzer, klarer Titel
- code: kurzer slug ohne Leerzeichen
- description: 1–2 Sätze
- learning_goal: konkret überprüfbares Lernziel
- why_relevant: warum dieses Thema für Schüler sinnvoll ist
- curriculum_reference: kurzer Hinweis, auf welchen Kompetenz-/Lehrplanbereich sich das Thema allgemein bezieht
- quiz_ideas: 2–4 konkrete Quizideen

Jede Quizidee enthält:
- title
- description
- learning_goal
- difficulty_mix

Wichtig:
- keine zu allgemeinen Themen wie „Mathe Grundlagen“ oder „Geographie allgemein“
- keine reinen Faktenlisten, wenn ein Kompetenzbezug sinnvoller ist
- lieber realistisch-schulnah als überpräzise erfunden
");
}

function saveCurriculumTopic(PDO $pdo, array $data): int
{
    $code = slugify($data['topic_code'] ?: $data['topic_title']);

    $stmt = $pdo->prepare("
        INSERT INTO curriculum_topics
          (state_id, school_type_id, grade, subject_id, code, title, description, learning_goal, sort_order, ai_generated)
        VALUES
          (:state_id, :school_type_id, :grade, :subject_id, :code, :title, :description, :learning_goal, 0, 1)
        ON DUPLICATE KEY UPDATE
          title = VALUES(title),
          description = VALUES(description),
          learning_goal = VALUES(learning_goal),
          ai_generated = 1
    ");

    $stmt->execute([
        'state_id' => (int)$data['state_id'],
        'school_type_id' => (int)$data['school_type_id'],
        'grade' => (int)$data['grade'],
        'subject_id' => (int)$data['subject_id'],
        'code' => $code,
        'title' => trim($data['topic_title']),
        'description' => trim($data['topic_description']),
        'learning_goal' => trim($data['topic_learning_goal']),
    ]);

    if ($pdo->lastInsertId()) {
        return (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("
        SELECT id FROM curriculum_topics
        WHERE state_id = :state_id AND school_type_id = :school_type_id
          AND grade = :grade AND subject_id = :subject_id AND code = :code
        LIMIT 1
    ");
    $stmt->execute([
        'state_id' => (int)$data['state_id'],
        'school_type_id' => (int)$data['school_type_id'],
        'grade' => (int)$data['grade'],
        'subject_id' => (int)$data['subject_id'],
        'code' => $code,
    ]);

    return (int)$stmt->fetchColumn();
}

function createQuizFromPost(PDO $pdo, array $post): array
{
    $topicId = saveCurriculumTopic($pdo, $post);

    $quizTitle = trim($post['quiz_title'] ?? '');
    $quizDescription = trim($post['quiz_description'] ?? '');
    $quizLearningGoal = trim($post['quiz_learning_goal'] ?? '');

    if (!$quizTitle) throw new RuntimeException('Quiz-Titel fehlt.');

    $quizKey = slugify($quizTitle . '-' . uniqid());

    $stmt = $pdo->prepare("
        INSERT INTO quizzes
          (quiz_key, title, description, subject_id, grade, questions_path, is_active, status, source_type, ai_generated)
        VALUES
          (:quiz_key, :title, :description, :subject_id, :grade, '', 1, 'draft', 'system', 1)
    ");
    $stmt->execute([
        'quiz_key' => $quizKey,
        'title' => $quizTitle,
        'description' => $quizDescription,
        'subject_id' => (int)$post['subject_id'],
        'grade' => (int)$post['grade'],
    ]);

    $quizId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO quiz_topic_map (quiz_id, topic_id) VALUES (:quiz_id, :topic_id)");
    $stmt->execute(['quiz_id' => $quizId, 'topic_id' => $topicId]);

    return [
        'quiz_id' => $quizId,
        'quiz_key' => $quizKey,
        'title' => $quizTitle,
        'description' => $quizDescription,
        'learning_goal' => $quizLearningGoal,
        'state_name' => $post['state_name'],
        'school_type_name' => $post['school_type_name'],
        'grade' => (int)$post['grade'],
        'subject_name' => $post['subject_name'],
        'topic_title' => $post['topic_title'],
        'topic_learning_goal' => $post['topic_learning_goal'],
        'official_sources' => $post['official_sources'] ?? '',
        'source_notes' => $post['source_notes'] ?? '',
    ];
}

function generateQuestionsForQuiz(array $quiz): array
{
    $prompt = trim("
Erstelle 15 Multiple-Choice-Fragen für ein Elevaro-Quiz.

Kontext:
- Bundesland: {$quiz['state_name']}
- Schulart: {$quiz['school_type_name']}
- Klasse: {$quiz['grade']}
- Fach: {$quiz['subject_name']}
- Thema: {$quiz['topic_title']}
- Quiz-Titel: {$quiz['title']}
- Lernziel Thema: {$quiz['topic_learning_goal']}
- Lernziel Quiz: {$quiz['learning_goal']}
- Offizielle Quellen/Links: {$quiz['official_sources']}
- Aus Quellen übernommene Stichpunkte/Kompetenzen: {$quiz['source_notes']}

Wichtige Regel:
Nutze die mitgegebenen Quellen/Stichpunkte als wichtigste Grundlage, erfinde aber keine exakten Zitate.

Anforderungen:
- genau 15 Fragen
- exakt 4 Antwortmöglichkeiten pro Frage
- genau eine richtige Antwort
- altersgerechte Formulierung
- kurze Erklärung
- typische Schülerfehler als plausible falsche Antworten
- ungefähr 5 leicht, 6 mittel, 4 schwer
- difficulty als Zahl zwischen 0.05 und 0.95
- keine uneindeutigen Antworten
");

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'questions' => [
                'type' => 'array',
                'minItems' => 15,
                'maxItems' => 15,
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
                        'difficulty' => ['type' => 'number', 'minimum' => 0.05, 'maximum' => 0.95],
                        'difficulty_label' => ['type' => 'string', 'enum' => ['leicht','mittel','schwer']],
                        'common_mistake' => ['type' => 'string']
                    ],
                    'required' => ['question','options','answer','explanation','difficulty','difficulty_label','common_mistake']
                ]
            ]
        ],
        'required' => ['questions']
    ];

    $result = elevaro_openai_chat_json([
        ['role' => 'system', 'content' => 'Du bist ein erfahrener Lehrer und erstellst didaktisch saubere Multiple-Choice-Fragen. Du lieferst ausschließlich valide JSON-Daten.'],
        ['role' => 'user', 'content' => $prompt],
    ], $schema, 0.40);

    return $result['json'];
}

function saveQuestionsAsDraft(PDO $pdo, int $quizId, array $questions): void
{
    $sortOrder = 0;

    foreach ($questions as $q) {
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
                INSERT INTO question_options (question_id, option_text, is_correct, sort_order)
                VALUES (:question_id, :option_text, :is_correct, :sort_order)
            ");
            $stmt->execute([
                'question_id' => $questionId,
                'option_text' => $option,
                'is_correct' => $option === $q['answer'] ? 1 : 0,
                'sort_order' => $i + 1,
            ]);
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO question_stats (question_id, calculated_difficulty) VALUES (:question_id, :difficulty)");
        $stmt->execute(['question_id' => $questionId, 'difficulty' => $q['difficulty']]);
    }
}

function findById(array $items, int $id): ?array
{
    foreach ($items as $item) if ((int)$item['id'] === $id) return $item;
    return null;
}

function indentLines(string $text): string
{
    $lines = preg_split('/\R/u', trim($text));
    return implode("\n", array_map(fn($line) => "  - " . trim($line), array_filter($lines)));
}

function slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    $text = trim($text, '-');
    return mb_substr($text, 0, 150, 'UTF-8') ?: uniqid('item_', true);
}

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>KI Curriculum Wizard – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/ai_wizard.css" rel="stylesheet">
</head>
<body class="p-4">
  <div class="container admin-shell">
    <div class="hero-card ai-glow card mb-4">
      <div class="card-body p-4">
        <h1 class="brand fw-bold mb-1">Elevaro Admin</h1>
        <h2 class="h3 fw-bold">KI-gestützter Curriculum- & Quiz-Wizard</h2>
        <p class="text-muted mb-0">Wähle Kontext, ergänze offizielle Quellen/Stichpunkte und generiere daraus Themen und Quizideen.</p>
      </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="card step-card mb-4">
      <div class="card-body p-4">
        <h3 class="fw-bold">1. Kontext & Quellen</h3>
        <form method="post">
          <input type="hidden" name="action" value="generate_topics">

          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label fw-bold">Bundesland</label>
              <select class="form-select" name="state_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($states as $state): ?>
                  <option value="<?= (int)$state['id'] ?>"><?= h($state['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label fw-bold">Schulart</label>
              <select class="form-select" name="school_type_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($schoolTypes as $type): ?>
                  <option value="<?= (int)$type['id'] ?>"><?= h($type['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2">
              <label class="form-label fw-bold">Klasse</label>
              <input class="form-control" name="grade" type="number" min="1" max="13" value="5" required>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-bold">Fach</label>
              <select class="form-select" name="subject_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($subjects as $subject): ?>
                  <option value="<?= (int)$subject['id'] ?>"><?= h(($subject['icon'] ?? '') . ' ' . $subject['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Optionaler Schwerpunkt</label>
              <input class="form-control" name="focus" placeholder="z. B. Orientierung, Grammatik, Brüche, Wortarten …">
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Offizielle Quellen/Links</label>
              <textarea class="form-control" name="official_sources" rows="3" placeholder="z. B. Link zum offiziellen Bildungsplan des Landes, Beispielcurriculum, PDF-Link …"></textarea>
              <div class="form-text">Die API kann Links nicht live öffnen. Die Links dienen als Nachweis/Debug und werden im Prompt genannt.</div>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Stichpunkte aus dem Bildungsplan</label>
              <textarea class="form-control" name="source_notes" rows="5" placeholder="Kopiere hier relevante Kompetenzbereiche/Stichpunkte aus der offiziellen Quelle rein, z. B. Grundlagen der Orientierung, Wetter und Klima, Lebensraum Stadt …"></textarea>
              <div class="form-text">Diese Stichpunkte sind der wichtigste Kontext für die KI.</div>
            </div>
          </div>

          <button class="btn btn-primary btn-lg mt-4">✨ Themen & Quizideen generieren</button>
        </form>
      </div>
    </div>

    <?php if ($generatedTopics): ?>
      <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
          <h5 class="fw-bold">🧪 Verwendeter Prompt</h5>
          <pre style="white-space: pre-wrap; font-size: 12px; max-height: 420px; overflow:auto;"><?= h($generatedTopics['prompt']) ?></pre>
        </div>
      </div>

      <h3 class="fw-bold">2. Vorschläge prüfen & Quiz auswählen</h3>

      <?php foreach ($generatedTopics['topics'] as $topic): ?>
        <div class="card topic-card mb-4">
          <div class="card-body p-4">
            <span class="badge badge-soft mb-2">
              <?= h($generatedTopics['context']['state_name']) ?> ·
              <?= h($generatedTopics['context']['school_type_name']) ?> ·
              Klasse <?= (int)$generatedTopics['context']['grade'] ?> ·
              <?= h($generatedTopics['context']['subject_name']) ?>
            </span>
            <h4 class="fw-bold"><?= h($topic['title']) ?></h4>
            <p class="text-muted"><?= h($topic['description']) ?></p>
            <p><strong>Lernziel:</strong> <?= h($topic['learning_goal']) ?></p>
            <p class="small-muted"><strong>Lehrplanbezug:</strong> <?= h($topic['curriculum_reference']) ?></p>
            <p class="small-muted"><strong>Warum relevant:</strong> <?= h($topic['why_relevant']) ?></p>

            <h5 class="fw-bold mt-4">Quizideen</h5>
            <div class="row g-3">
              <?php foreach ($topic['quiz_ideas'] as $quizIdea): ?>
                <div class="col-md-6">
                  <div class="card quiz-card h-100">
                    <div class="card-body">
                      <h6 class="fw-bold"><?= h($quizIdea['title']) ?></h6>
                      <p class="text-muted"><?= h($quizIdea['description']) ?></p>
                      <p class="small-muted"><strong>Lernziel:</strong> <?= h($quizIdea['learning_goal']) ?></p>
                      <p class="small-muted"><strong>Mix:</strong> <?= h($quizIdea['difficulty_mix']) ?></p>

                      <form method="post">
                        <input type="hidden" name="action" value="create_quiz_and_questions">
                        <?php foreach ($generatedTopics['context'] as $key => $value): ?>
                          <input type="hidden" name="<?= h($key) ?>" value="<?= h($value) ?>">
                        <?php endforeach; ?>
                        <input type="hidden" name="topic_code" value="<?= h($topic['code']) ?>">
                        <input type="hidden" name="topic_title" value="<?= h($topic['title']) ?>">
                        <input type="hidden" name="topic_description" value="<?= h($topic['description']) ?>">
                        <input type="hidden" name="topic_learning_goal" value="<?= h($topic['learning_goal']) ?>">
                        <input type="hidden" name="quiz_title" value="<?= h($quizIdea['title']) ?>">
                        <input type="hidden" name="quiz_description" value="<?= h($quizIdea['description']) ?>">
                        <input type="hidden" name="quiz_learning_goal" value="<?= h($quizIdea['learning_goal']) ?>">
                        <button class="btn btn-primary">Quiz erstellen & 15 Fragen generieren</button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</body>
</html>
