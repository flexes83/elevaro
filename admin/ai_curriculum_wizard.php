<?php
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/openai_client.php';

$pdo = elevaro_db();

$action = $_POST['action'] ?? '';
$error = null;
$activeBatch = null;

$states = $pdo->query("SELECT id, code, name FROM states ORDER BY sort_order, name")->fetchAll();
$schoolTypes = $pdo->query("SELECT id, code, name FROM school_types ORDER BY sort_order, name")->fetchAll();
$subjects = $pdo->query("SELECT id, code, name, icon FROM subjects ORDER BY sort_order, name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'generate_topics') {
            $batchId = generateAndCacheTopics($pdo, $_POST, $states, $schoolTypes, $subjects);
            header('Location: ai_curriculum_wizard.php?batch_id=' . (int)$batchId);
            exit;
        }

        if ($action === 'create_quiz_from_idea') {
            $quizId = createQuizAndQuestionsFromIdea($pdo, (int)$_POST['quiz_idea_id']);
            header('Location: quiz_questions.php?quiz_id=' . (int)$quizId . '&ai_generated=1');
            exit;
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$batchId = (int)($_GET['batch_id'] ?? 0);
if ($batchId) {
    $activeBatch = loadBatch($pdo, $batchId);
}

$recentBatches = $pdo->query("
    SELECT b.id, b.grade, b.focus, b.created_at,
           s.name AS state_name, st.name AS school_type_name, sub.name AS subject_name
    FROM ai_topic_batches b
    JOIN states s ON s.id = b.state_id
    JOIN school_types st ON st.id = b.school_type_id
    JOIN subjects sub ON sub.id = b.subject_id
    WHERE b.status = 'success'
    ORDER BY b.created_at DESC
    LIMIT 8
")->fetchAll();

function generateAndCacheTopics(PDO $pdo, array $post, array $states, array $schoolTypes, array $subjects): int
{
    $context = collectContext($post, $states, $schoolTypes, $subjects);
    $prompt = buildTopicPrompt($context);

    $schema = topicSchema();

    try {
        $result = elevaro_openai_chat_json([
            [
                'role' => 'system',
                'content' => 'Du bist ein erfahrener deutscher Lehrer und Curriculum-Experte. Du strukturierst Lernquizze nach Bundesland, Schulart, Klassenstufe und Fach. Du lieferst ausschließlich valide JSON-Daten.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ],
        ], $schema, 0.32);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO ai_topic_batches
              (state_id, school_type_id, subject_id, grade, focus, official_sources, curriculum_notes, prompt, response, status)
            VALUES
              (:state_id, :school_type_id, :subject_id, :grade, :focus, :official_sources, :curriculum_notes, :prompt, :response, 'success')
        ");
        $stmt->execute([
            'state_id' => $context['state_id'],
            'school_type_id' => $context['school_type_id'],
            'subject_id' => $context['subject_id'],
            'grade' => $context['grade'],
            'focus' => $context['focus'],
            'official_sources' => $context['official_sources'],
            'curriculum_notes' => $context['curriculum_notes'],
            'prompt' => $prompt,
            'response' => $result['content'],
        ]);

        $batchId = (int)$pdo->lastInsertId();

        foreach ($result['json']['topics'] as $topic) {
            $stmt = $pdo->prepare("
                INSERT INTO ai_topic_suggestions
                  (batch_id, title, code, description, learning_goal, why_relevant)
                VALUES
                  (:batch_id, :title, :code, :description, :learning_goal, :why_relevant)
            ");
            $stmt->execute([
                'batch_id' => $batchId,
                'title' => $topic['title'],
                'code' => slugify($topic['code'] ?: $topic['title']),
                'description' => $topic['description'],
                'learning_goal' => $topic['learning_goal'],
                'why_relevant' => $topic['why_relevant'],
            ]);

            $topicSuggestionId = (int)$pdo->lastInsertId();

            foreach ($topic['quiz_ideas'] as $idea) {
                $stmt = $pdo->prepare("
                    INSERT INTO ai_quiz_ideas
                      (topic_suggestion_id, title, description, learning_goal, difficulty_mix)
                    VALUES
                      (:topic_suggestion_id, :title, :description, :learning_goal, :difficulty_mix)
                ");
                $stmt->execute([
                    'topic_suggestion_id' => $topicSuggestionId,
                    'title' => $idea['title'],
                    'description' => $idea['description'],
                    'learning_goal' => $idea['learning_goal'],
                    'difficulty_mix' => $idea['difficulty_mix'],
                ]);
            }
        }

        $pdo->commit();
        return $batchId;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $stmt = $pdo->prepare("
            INSERT INTO ai_topic_batches
              (state_id, school_type_id, subject_id, grade, focus, official_sources, curriculum_notes, prompt, response, status, error_message)
            VALUES
              (:state_id, :school_type_id, :subject_id, :grade, :focus, :official_sources, :curriculum_notes, :prompt, NULL, 'failed', :error)
        ");
        $stmt->execute([
            'state_id' => $context['state_id'],
            'school_type_id' => $context['school_type_id'],
            'subject_id' => $context['subject_id'],
            'grade' => $context['grade'],
            'focus' => $context['focus'],
            'official_sources' => $context['official_sources'],
            'curriculum_notes' => $context['curriculum_notes'],
            'prompt' => $prompt,
            'error' => $e->getMessage(),
        ]);

        throw $e;
    }
}

function createQuizAndQuestionsFromIdea(PDO $pdo, int $quizIdeaId): int
{
    $stmt = $pdo->prepare("
        SELECT
          qi.*,
          ts.title AS topic_title,
          ts.code AS topic_code,
          ts.description AS topic_description,
          ts.learning_goal AS topic_learning_goal,
          ts.id AS topic_suggestion_id,
          b.state_id, b.school_type_id, b.subject_id, b.grade,
          b.official_sources, b.curriculum_notes,
          s.name AS state_name,
          st.name AS school_type_name,
          sub.name AS subject_name
        FROM ai_quiz_ideas qi
        JOIN ai_topic_suggestions ts ON ts.id = qi.topic_suggestion_id
        JOIN ai_topic_batches b ON b.id = ts.batch_id
        JOIN states s ON s.id = b.state_id
        JOIN school_types st ON st.id = b.school_type_id
        JOIN subjects sub ON sub.id = b.subject_id
        WHERE qi.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $quizIdeaId]);
    $idea = $stmt->fetch();

    if (!$idea) {
        throw new RuntimeException('Quizidee nicht gefunden.');
    }

    $pdo->beginTransaction();

    $topicId = ensureCurriculumTopic($pdo, $idea);

    $quizKey = slugify($idea['title'] . '-' . uniqid());

    $stmt = $pdo->prepare("
        INSERT INTO quizzes
          (quiz_key, title, description, subject_id, grade, questions_path, is_active, status, source_type, ai_generated)
        VALUES
          (:quiz_key, :title, :description, :subject_id, :grade, '', 1, 'draft', 'system', 1)
    ");
    $stmt->execute([
        'quiz_key' => $quizKey,
        'title' => $idea['title'],
        'description' => $idea['description'],
        'subject_id' => (int)$idea['subject_id'],
        'grade' => (int)$idea['grade'],
    ]);

    $quizId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO quiz_topic_map (quiz_id, topic_id) VALUES (:quiz_id, :topic_id)");
    $stmt->execute(['quiz_id' => $quizId, 'topic_id' => $topicId]);

    $stmt = $pdo->prepare("UPDATE ai_quiz_ideas SET created_quiz_id = :quiz_id WHERE id = :id");
    $stmt->execute(['quiz_id' => $quizId, 'id' => $quizIdeaId]);

    $pdo->commit();

    $questions = generateQuestionsForIdea($idea);
    saveQuizTags($pdo, $quizId, $questions['tags'] ?? []);
    saveQuestionsAsDraft($pdo, $quizId, $questions['questions']);

    return $quizId;
}

function ensureCurriculumTopic(PDO $pdo, array $idea): int
{
    $code = slugify($idea['topic_code'] ?: $idea['topic_title']);

    $stmt = $pdo->prepare("
        INSERT INTO curriculum_topics
          (state_id, school_type_id, grade, subject_id, code, title, description, learning_goal, ai_generated)
        VALUES
          (:state_id, :school_type_id, :grade, :subject_id, :code, :title, :description, :learning_goal, 1)
        ON DUPLICATE KEY UPDATE
          title = VALUES(title),
          description = VALUES(description),
          learning_goal = VALUES(learning_goal),
          ai_generated = 1
    ");
    $stmt->execute([
        'state_id' => (int)$idea['state_id'],
        'school_type_id' => (int)$idea['school_type_id'],
        'grade' => (int)$idea['grade'],
        'subject_id' => (int)$idea['subject_id'],
        'code' => $code,
        'title' => $idea['topic_title'],
        'description' => $idea['topic_description'],
        'learning_goal' => $idea['topic_learning_goal'],
    ]);

    if ($pdo->lastInsertId()) {
        return (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("
        SELECT id FROM curriculum_topics
        WHERE state_id = :state_id
          AND school_type_id = :school_type_id
          AND grade = :grade
          AND subject_id = :subject_id
          AND code = :code
        LIMIT 1
    ");
    $stmt->execute([
        'state_id' => (int)$idea['state_id'],
        'school_type_id' => (int)$idea['school_type_id'],
        'grade' => (int)$idea['grade'],
        'subject_id' => (int)$idea['subject_id'],
        'code' => $code,
    ]);

    return (int)$stmt->fetchColumn();
}

function generateQuestionsForIdea(array $idea): array
{
    $prompt = buildQuestionPrompt($idea);

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'tags' => [
                'type' => 'array',
                'minItems' => 2,
                'maxItems' => 5,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'slug' => ['type' => 'string'],
                        'name' => ['type' => 'string']
                    ],
                    'required' => ['slug','name']
                ]
            ],
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
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'text' => ['type' => 'string'],
                                    'media_prompt' => ['type' => 'string'],
                                    'media_search_terms' => ['type' => 'string']
                                ],
                                'required' => ['text','media_prompt','media_search_terms']
                            ]
                        ],
                        'answer' => ['type' => 'string'],
                        'explanation' => ['type' => 'string'],
                        'difficulty' => ['type' => 'number', 'minimum' => 0.05, 'maximum' => 0.95],
                        'difficulty_label' => ['type' => 'string', 'enum' => ['leicht','mittel','schwer']],
                        'common_mistake' => ['type' => 'string'],
                        'media_recommendation' => ['type' => 'string', 'enum' => ['none','question_image','answer_images']],
                        'media_prompt' => ['type' => 'string'],
                        'media_search_terms' => ['type' => 'string']
                    ],
                    'required' => [
                        'question',
                        'options',
                        'answer',
                        'explanation',
                        'difficulty',
                        'difficulty_label',
                        'common_mistake',
                        'media_recommendation',
                        'media_prompt',
                        'media_search_terms'
                    ]
                ]
            ]
        ],
        'required' => ['tags','questions']
    ];

    $result = elevaro_openai_chat_json([
        ['role' => 'system', 'content' => 'Du bist ein erfahrener Lehrer und erstellst didaktisch saubere Multiple-Choice-Fragen. Du entscheidest sehr zurückhaltend, ob Bilder didaktisch sinnvoll sind. Du lieferst ausschließlich valide JSON-Daten.'],
        ['role' => 'user', 'content' => $prompt],
    ], $schema, 0.42);

    return $result['json'];
}

function buildQuestionPrompt(array $idea): string
{
    return trim("
Erstelle 15 Multiple-Choice-Fragen für ein Elevaro-Quiz.

Kontext:
- Bundesland: {$idea['state_name']}
- Schulart: {$idea['school_type_name']}
- Klasse: {$idea['grade']}
- Fach: {$idea['subject_name']}
- Thema: {$idea['topic_title']}
- Quiz-Titel: {$idea['title']}
- Lernziel Thema: {$idea['topic_learning_goal']}
- Lernziel Quiz: {$idea['learning_goal']}

Offizielle Quellen / Links:
{$idea['official_sources']}

Auszüge / Stichpunkte aus Bildungsplan:
{$idea['curriculum_notes']}

Allgemeine Regeln:
- Falls die Quellen/Stichpunkte konkrete Kompetenzen nennen, orientiere dich daran.
- Erfinde keine exakten Bildungsplanformulierungen.
- Fragen müssen altersgerecht und eindeutig sein.
- exakt 4 Antwortmöglichkeiten, genau eine richtig.
- ungefähr 5 leicht, 6 mittel, 4 schwer.
- falsche Antworten sollen typische Missverständnisse abbilden.

Tag-Regeln:

- Gib 2–5 abstrakte Tags für das gesamte Quiz zurück.
- Tags sind breite Lernbereiche, keine einzelne Frage und kein zu enges Detail.
- Beispiele:
  → Ornithologie
  → Vogelarten
  → Bruchrechnen
  → Simple Past
  → Kartenkunde
  → Demonstrativpronomen
  → Geometrie
- slug ist kleingeschrieben, ohne Leerzeichen, mit Bindestrichen, z. B. \"simple-past\" oder \"bruchrechnen\".
- name ist die schöne Anzeigeform, z. B. "Simple Past" oder "Ornithologie".
- Nutze keine Tags wie "Quiz", "Klasse 5" oder "leicht".

Medien-Regeln:

- Prüfe, ob das Thema visuelles Erkennen erfordert (z. B. Tiere, Pflanzen, Karten, Diagramme, Formen, Objekte).
- Wenn visuelles Lernen zentral ist:
  → 40–70 % der Fragen mit Bildern
  → bevorzugt media_recommendation = 'question_image'
  → typische Frage: \"Welcher ... ist hier abgebildet?\"
- Wenn visuelle Unterscheidung wichtig ist:
  → media_recommendation = 'answer_images'
- Bei abstrakten Themen:
  → media_recommendation = 'none'
- Bei Bildern:
  → media_prompt = konkrete Bildbeschreibung (z. B. \"realistic photo of a magpie bird sitting on a branch, side view\")
  → media_search_terms = kurze Suchbegriffe (z. B. \"Elster Vogel Seitenansicht\")
- Bei answer_images:
  → jede Option bekommt eigenen media_prompt + media_search_terms
- Bei 'none':
  → media_prompt und media_search_terms leer lassen
- Bilder werden später im Admin geprüft, nicht automatisch verwendet.
");
}


function saveQuizTags(PDO $pdo, int $quizId, array $tags): void
{
    foreach ($tags as $tag) {
        $name = trim((string)($tag['name'] ?? ''));
        $slug = trim((string)($tag['slug'] ?? ''));

        if ($name === '' && $slug === '') {
            continue;
        }

        if ($slug === '') {
            $slug = slugify($name);
        }

        $slug = normalizeTagSlug($slug);
        if ($slug === '') {
            continue;
        }

        if ($name === '') {
            $name = ucwords(str_replace('-', ' ', $slug));
        }

        $stmt = $pdo->prepare("
            INSERT INTO tags (slug, name)
            VALUES (:slug, :name)
            ON DUPLICATE KEY UPDATE name = VALUES(name)
        ");
        $stmt->execute([
            'slug' => $slug,
            'name' => $name,
        ]);

        $stmt = $pdo->prepare("SELECT id FROM tags WHERE slug = :slug LIMIT 1");
        $stmt->execute(['slug' => $slug]);
        $tagId = (int)$stmt->fetchColumn();

        if (!$tagId) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO quiz_tag_map (quiz_id, tag_id)
            VALUES (:quiz_id, :tag_id)
        ");
        $stmt->execute([
            'quiz_id' => $quizId,
            'tag_id' => $tagId,
        ]);
    }
}

function normalizeTagSlug(string $slug): string
{
    $slug = mb_strtolower($slug, 'UTF-8');
    $slug = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return mb_substr($slug, 0, 120, 'UTF-8');
}


function saveQuestionsAsDraft(PDO $pdo, int $quizId, array $questions): void
{
    $sortOrder = 0;

    foreach ($questions as $q) {
        $sortOrder++;

        $optionTexts = array_map(static function ($option) {
            return is_array($option) ? (string)$option['text'] : (string)$option;
        }, $q['options']);

        if (!in_array($q['answer'], $optionTexts, true)) {
            $q['options'][0] = [
                'text' => $q['answer'],
                'media_prompt' => '',
                'media_search_terms' => ''
            ];
            shuffle($q['options']);
        }

        $mediaRecommendation = $q['media_recommendation'] ?? 'none';
        if (!in_array($mediaRecommendation, ['none','question_image','answer_images'], true)) {
            $mediaRecommendation = 'none';
        }

        $stmt = $pdo->prepare("
            INSERT INTO questions
              (quiz_id, question_key, type, question_text,
               media_type, media_path, media_alt,
               media_recommendation, media_prompt, media_search_terms,
               correct_answer, explanation,
               difficulty_manual, difficulty_calculated, status, ai_generated, sort_order)
            VALUES
              (:quiz_id, :question_key, 'mc', :question_text,
               'none', NULL, NULL,
               :media_recommendation, :media_prompt, :media_search_terms,
               :correct_answer, :explanation,
               :difficulty_manual, :difficulty_calculated, 'draft', 1, :sort_order)
        ");
        $stmt->execute([
            'quiz_id' => $quizId,
            'question_key' => slugify($q['question']) . '-' . substr(md5(uniqid('', true)), 0, 6),
            'question_text' => $q['question'],
            'media_recommendation' => $mediaRecommendation,
            'media_prompt' => $mediaRecommendation === 'question_image' ? ($q['media_prompt'] ?? '') : '',
            'media_search_terms' => $mediaRecommendation === 'question_image' ? ($q['media_search_terms'] ?? '') : '',
            'correct_answer' => $q['answer'],
            'explanation' => trim($q['explanation'] . "\n\nTypischer Fehler: " . $q['common_mistake']),
            'difficulty_manual' => $q['difficulty'],
            'difficulty_calculated' => $q['difficulty'],
            'sort_order' => $sortOrder,
        ]);

        $questionId = (int)$pdo->lastInsertId();

        foreach (array_values($q['options']) as $i => $option) {
            $text = is_array($option) ? (string)$option['text'] : (string)$option;

            $optionMediaPrompt = '';
            $optionMediaSearchTerms = '';

            if ($mediaRecommendation === 'answer_images' && is_array($option)) {
                $optionMediaPrompt = $option['media_prompt'] ?? '';
                $optionMediaSearchTerms = $option['media_search_terms'] ?? '';
            }

            $stmt = $pdo->prepare("
                INSERT INTO question_options
                  (question_id, option_text, media_type, media_path, media_alt,
                   media_prompt, media_search_terms, is_correct, sort_order)
                VALUES
                  (:question_id, :option_text, 'none', NULL, NULL,
                   :media_prompt, :media_search_terms, :is_correct, :sort_order)
            ");
            $stmt->execute([
                'question_id' => $questionId,
                'option_text' => $text,
                'media_prompt' => $optionMediaPrompt,
                'media_search_terms' => $optionMediaSearchTerms,
                'is_correct' => $text === $q['answer'] ? 1 : 0,
                'sort_order' => $i + 1,
            ]);
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO question_stats (question_id, calculated_difficulty)
            VALUES (:question_id, :difficulty)
        ");
        $stmt->execute(['question_id' => $questionId, 'difficulty' => $q['difficulty']]);
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
        'state_name' => $state['name'],
        'school_type_id' => (int)$schoolType['id'],
        'school_type_name' => $schoolType['name'],
        'subject_id' => (int)$subject['id'],
        'subject_name' => $subject['name'],
        'grade' => $grade,
        'focus' => trim($post['focus'] ?? ''),
        'official_sources' => trim($post['official_sources'] ?? ''),
        'curriculum_notes' => trim($post['curriculum_notes'] ?? ''),
    ];
}

function buildTopicPrompt(array $context): string
{
    $focus = $context['focus'] ? "- Zusätzlicher Schwerpunkt/Hinweis: {$context['focus']}" : "- Zusätzlicher Schwerpunkt/Hinweis: keiner";

    return trim("
Erstelle eine Liste sinnvoller, lehrplannaher Themen für Lernquizze.

Kontext:
- Bundesland: {$context['state_name']}
- Schulart: {$context['school_type_name']}
- Klassenstufe: {$context['grade']}
- Fach: {$context['subject_name']}
{$focus}

Offizielle Quellen / Links:
{$context['official_sources']}

Auszüge / Stichpunkte aus dem Bildungsplan:
{$context['curriculum_notes']}

Ziel:
Die Themen sollen für kurze, motivierende Lernquizze geeignet sein und sich am typischen Bildungsplan bzw. Lehrplan des angegebenen Kontextes orientieren.

Wenn du den exakten Bildungsplan nicht sicher kennst:
- erfinde keine spezifischen Lehrplanformulierungen
- bleibe allgemein schulnah
- wähle Themen, die für diese Klassenstufe realistisch und üblich sind
- formuliere keine Behauptung, dass ein Thema exakt im Bildungsplan steht

Didaktische Anforderungen:
- altersgerecht für die angegebene Klassenstufe
- passend zur angegebenen Schulart
- vom Einfachen zum Schwierigen aufgebaut
- nicht nur reines Auswendiglernen
- Fokus auf Verstehen, Anwenden, Erkennen, Zuordnen, Vergleichen und Einordnen
- typische Schülerfehler oder Missverständnisse berücksichtigen
- gut in kurzen Quiz-Sessions abbildbar

Fachspezifische Orientierung:
- Sprachen: Wortschatz, Grammatik, Sprachverständnis, Anwendung in Beispielsätzen
- Mathematik: Begriffe, Rechenwege, Muster, typische Fehler, einfache Anwendungen
- Naturwissenschaften: Beobachten, Beschreiben, Zusammenhänge, einfache Modelle
- Gesellschafts-/Geofächer: Orientierung, Begriffe, Zusammenhänge, Karten/Quellen/Bilder verstehen
- Deutsch: Sprachgefühl, Grammatik, Lesen, Textverständnis, Schreiben
- Sach-/Grundschulfächer: Alltagsbezug, Beobachten, Zuordnen, Grundbegriffe

Liefere 6 bis 10 Themen.
");
}

function topicSchema(): array
{
    return [
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
                    'required' => ['title','code','description','learning_goal','why_relevant','quiz_ideas']
                ]
            ]
        ],
        'required' => ['topics']
    ];
}

function loadBatch(PDO $pdo, int $batchId): ?array
{
    $stmt = $pdo->prepare("
        SELECT b.*, s.name AS state_name, st.name AS school_type_name, sub.name AS subject_name
        FROM ai_topic_batches b
        JOIN states s ON s.id = b.state_id
        JOIN school_types st ON st.id = b.school_type_id
        JOIN subjects sub ON sub.id = b.subject_id
        WHERE b.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $batchId]);
    $batch = $stmt->fetch();

    if (!$batch) return null;

    $stmt = $pdo->prepare("SELECT * FROM ai_topic_suggestions WHERE batch_id = :batch_id ORDER BY id ASC");
    $stmt->execute(['batch_id' => $batchId]);
    $topics = $stmt->fetchAll();

    foreach ($topics as &$topic) {
        $stmt = $pdo->prepare("SELECT * FROM ai_quiz_ideas WHERE topic_suggestion_id = :id ORDER BY id ASC");
        $stmt->execute(['id' => $topic['id']]);
        $topic['quiz_ideas'] = $stmt->fetchAll();
    }

    $batch['topics'] = $topics;
    return $batch;
}

function findById(array $items, int $id): ?array
{
    foreach ($items as $item) {
        if ((int)$item['id'] === $id) return $item;
    }
    return null;
}

function slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    $text = trim($text, '-');
    return mb_substr($text, 0, 150, 'UTF-8') ?: uniqid('item_', true);
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
  <title>KI Curriculum Wizard – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/ai_wizard.css" rel="stylesheet">
</head>
<body class="p-4">
  <div class="container admin-shell">

    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between gap-3 flex-wrap align-items-start">
          <div>
            <h1 class="brand fw-bold mb-1">Elevaro Admin</h1>
            <h2 class="h3 fw-bold">KI-gestützter Curriculum- & Quiz-Wizard</h2>
            <p class="text-muted mb-0">Themen werden gespeichert. Du kannst mehrere Quizze aus einer einmaligen KI-Auswahl erstellen.</p>
          </div>
        </div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <h3 class="fw-bold">1. Kontext auswählen</h3>
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
              <input class="form-control" name="focus" placeholder="z. B. Karten lesen, Simple Present, Brüche vergleichen …">
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Offizielle Quellen / Links</label>
              <textarea class="form-control" name="official_sources" rows="3" placeholder="Links zu Bildungsplanseiten oder offiziellen PDFs. Die API öffnet Links nicht automatisch, sie dienen als Referenz im Prompt."></textarea>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Auszüge / Stichpunkte aus dem Bildungsplan</label>
              <textarea class="form-control" name="curriculum_notes" rows="5" placeholder="Hier relevante Kompetenzen, Inhaltsfelder oder Stichpunkte aus der offiziellen Quelle einfügen. Das verbessert die Qualität deutlich."></textarea>
            </div>
          </div>

          <button class="btn btn-primary btn-lg mt-4">✨ Themen & Quizideen generieren</button>
        </form>
      </div>
    </div>

    <?php if ($activeBatch): ?>
      <div class="mb-3">
        <h3 class="fw-bold">2. Gespeicherte Vorschläge</h3>
        <p class="text-muted">
          <?= h($activeBatch['state_name']) ?> · <?= h($activeBatch['school_type_name']) ?> · Klasse <?= (int)$activeBatch['grade'] ?> · <?= h($activeBatch['subject_name']) ?>
        </p>
      </div>

      <?php foreach ($activeBatch['topics'] as $topic): ?>
        <div class="card card-soft mb-4">
          <div class="card-body p-4">
            <span class="badge badge-soft mb-2">Thema</span>
            <h4 class="fw-bold"><?= h($topic['title']) ?></h4>
            <p class="text-muted"><?= h($topic['description']) ?></p>
            <p><strong>Lernziel:</strong> <?= h($topic['learning_goal']) ?></p>
            <p class="small-muted"><strong>Warum relevant:</strong> <?= h($topic['why_relevant']) ?></p>

            <div class="row g-3">
              <?php foreach ($topic['quiz_ideas'] as $idea): ?>
                <div class="col-md-6">
                  <div class="card h-100">
                    <div class="card-body">
                      <h5 class="fw-bold"><?= h($idea['title']) ?></h5>
                      <p class="text-muted"><?= h($idea['description']) ?></p>
                      <p class="small-muted"><strong>Lernziel:</strong> <?= h($idea['learning_goal']) ?></p>
                      <p class="small-muted"><strong>Mix:</strong> <?= h($idea['difficulty_mix']) ?></p>

                      <?php if ($idea['created_quiz_id']): ?>
                        <a class="btn btn-success" href="quiz_questions.php?quiz_id=<?= (int)$idea['created_quiz_id'] ?>">Zum Quiz</a>
                      <?php else: ?>
                        <form method="post">
                          <input type="hidden" name="action" value="create_quiz_from_idea">
                          <input type="hidden" name="quiz_idea_id" value="<?= (int)$idea['id'] ?>">
                          <button class="btn btn-primary">Quiz erstellen & 15 Fragen generieren</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="card card-soft mb-4">
        <div class="card-body p-4">
          <h4 class="fw-bold">🧪 Verwendeter Prompt</h4>
          <pre class="debug-prompt"><?= h($activeBatch['prompt']) ?></pre>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($recentBatches): ?>
      <div class="card card-soft mb-4">
        <div class="card-body p-4">
          <h3 class="h5 fw-bold">Gespeicherte KI-Auswahlen</h3>
          <div class="list-group list-group-flush">
            <?php foreach ($recentBatches as $batch): ?>
              <a class="list-group-item list-group-item-action" href="?batch_id=<?= (int)$batch['id'] ?>">
                <?= h($batch['state_name']) ?> · <?= h($batch['school_type_name']) ?> · Klasse <?= (int)$batch['grade'] ?> · <?= h($batch['subject_name']) ?>
                <small class="text-muted d-block"><?= h($batch['created_at']) ?><?= $batch['focus'] ? ' · ' . h($batch['focus']) : '' ?></small>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>
</body>
</html>
