<?php
require_once __DIR__ . '/_layout.php';

$class = teacher_selected_class();
if (!$class) {
    teacher_header('Lerninhalte', 'Lege zuerst eine Klasse an.');
    echo '<div class="card card-soft"><div class="card-body p-4"><a class="btn btn-primary" href="classes.php">Klasse anlegen</a></div></div>';
    teacher_footer();
    exit;
}

$pdo = teacher_db();
$classId = (int)$class['id'];
$stateCode = (string)($class['state_code'] ?? '');
$schoolTypeCode = (string)($class['school_type_code'] ?? '');
$levelKey = (string)($class['level_key'] ?? '');
$subjectCode = (string)($class['subject_code'] ?? '');
$classLabel = teacher_class_label($class);

function teacher_learning_contents_fetch_topics(PDO $pdo, string $stateCode, string $schoolTypeCode, string $levelKey, string $subjectCode): array
{
    if ($stateCode === '' || $schoolTypeCode === '' || $levelKey === '' || $subjectCode === '') {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            c.*,
            COALESCE(NULLIF(c.domain_title, ''), 'Weitere Inhalte') AS domain_display
        FROM curriculum_topics_content c
        WHERE c.state_code = :state_code
          AND c.school_type_key = :school_type
          AND c.grade_key = :level_key
          AND c.subject_key = :subject
          AND COALESCE(c.is_active, 1) = 1
        ORDER BY c.domain_title ASC, c.sort_order ASC, c.topic_title ASC
    ");
    $stmt->execute([
        'state_code' => $stateCode,
        'school_type' => $schoolTypeCode,
        'level_key' => $levelKey,
        'subject' => $subjectCode,
    ]);

    $topics = $stmt->fetchAll() ?: [];
    if (!$topics) {
        return [];
    }

    $ids = array_map(static fn(array $topic): int => (int)$topic['id'], $topics);
    $subtopicsByTopic = [];

    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sub = $pdo->prepare("
            SELECT *
            FROM curriculum_topic_subtopics
            WHERE curriculum_topic_content_id IN ({$ph})
              AND COALESCE(is_active, 1) = 1
            ORDER BY curriculum_topic_content_id ASC, sort_order ASC, subtopic_title ASC
        ");
        $sub->execute($ids);

        foreach ($sub->fetchAll() ?: [] as $row) {
            $subtopicsByTopic[(int)$row['curriculum_topic_content_id']][] = $row;
        }
    }

    foreach ($topics as &$topic) {
        $topic['subtopics'] = $subtopicsByTopic[(int)$topic['id']] ?? [];
    }
    unset($topic);

    return $topics;
}

function teacher_learning_contents_group_by_domain(array $topics): array
{
    $grouped = [];
    foreach ($topics as $topic) {
        $domain = trim((string)($topic['domain_display'] ?? 'Weitere Inhalte')) ?: 'Weitere Inhalte';
        $grouped[$domain][] = $topic;
    }
    return $grouped;
}

function teacher_learning_contents_label_subject(string $subjectCode): string
{
    try {
        $stmt = teacher_db()->prepare("SELECT name FROM subjects WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $subjectCode]);
        $name = $stmt->fetchColumn();
        if ($name) return (string)$name;
    } catch (Throwable $e) {}
    return $subjectCode;
}

$topics = teacher_learning_contents_fetch_topics($pdo, $stateCode, $schoolTypeCode, $levelKey, $subjectCode);
$groupedTopics = teacher_learning_contents_group_by_domain($topics);
$subjectLabel = teacher_learning_contents_label_subject($subjectCode);

teacher_header('Lerninhalte', 'Themenübersicht für die aktuell ausgewählte Klasse.');
?>
<style>
  .learning-content-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:center;margin-bottom:22px}
  .learning-content-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .learning-content-meta span{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border-radius:999px;background:#f3f5ff;color:#4f46e5;font-weight:850;font-size:.84rem}
  .learning-domain-grid{display:grid;gap:18px}
  .learning-domain-card{border:1px solid rgba(23,32,51,.08);border-radius:26px;background:#fff;box-shadow:0 18px 45px rgba(23,32,51,.06);overflow:hidden}
  .learning-domain-head{padding:20px 22px;background:linear-gradient(135deg,rgba(90,79,243,.10),rgba(139,124,255,.05));display:flex;justify-content:space-between;gap:16px;align-items:center}
  .learning-domain-head h2{margin:0;font-size:1.15rem;font-weight:950;color:#172033}
  .learning-domain-head span{border-radius:999px;background:#fff;padding:6px 10px;font-weight:900;color:#5a4ff3;font-size:.8rem}
  .learning-topic-list{display:grid;gap:0}
  .learning-topic{padding:18px 22px;border-top:1px solid rgba(23,32,51,.07)}
  .learning-topic-title{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
  .learning-topic-title h3{margin:0;font-size:1.05rem;font-weight:950;color:#172033}
  .learning-topic-title small{color:#7b8494;font-weight:750}
  .learning-topic p{margin:.55rem 0 0;color:#5b6472;line-height:1.45}
  .learning-keywords{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
  .learning-keywords span{font-size:.75rem;font-weight:800;color:#475569;background:#f1f5f9;border-radius:999px;padding:4px 8px}
  .learning-subtopics{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
  .learning-subtopics span{font-size:.8rem;font-weight:850;color:#172033;background:#eef2ff;border:1px solid rgba(90,79,243,.13);border-radius:999px;padding:6px 10px}
  .learning-empty{padding:32px;border-radius:26px;background:#fff8ed;border:1px solid #fed7aa;color:#9a3412}
  .learning-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
  @media(max-width:900px){.learning-content-hero{grid-template-columns:1fr}.learning-actions{justify-content:flex-start}}
</style>

<div class="learning-content-hero">
  <div>
    <h2 class="h4 fw-black mb-1"><?= teacher_h($classLabel) ?></h2>
    <p class="text-muted mb-0">Hier siehst du die hinterlegten Themen und Skills für diese Klasse. Die Übersicht ist bewusst als Lerninhalt formuliert und nicht als verbindlicher Lehrplan.</p>
    <div class="learning-content-meta">
      <span>🏫 <?= teacher_h((string)$schoolTypeCode) ?></span>
      <span>🎚️ <?= teacher_h((string)$levelKey) ?></span>
      <span>📘 <?= teacher_h($subjectLabel) ?></span>
      <span><?= count($topics) ?> Themen</span>
    </div>
  </div>
  <div class="learning-actions">
    <a class="btn btn-primary" href="ai_wizard.php?class_id=<?= (int)$classId ?>">✨ Quiz aus Lerninhalt erstellen</a>
  </div>
</div>

<?php if (!$topics): ?>
  <div class="learning-empty">
    <strong>Noch keine Lerninhalte hinterlegt.</strong><br>
    Für diese Kombination aus Bundesland, Schulform, Stufe und Fach wurden noch keine Themen gefunden.
  </div>
<?php else: ?>
  <div class="learning-domain-grid">
    <?php foreach ($groupedTopics as $domain => $domainTopics): ?>
      <section class="learning-domain-card">
        <div class="learning-domain-head">
          <h2><?= teacher_h($domain) ?></h2>
          <span><?= count($domainTopics) ?> Themen</span>
        </div>
        <div class="learning-topic-list">
          <?php foreach ($domainTopics as $topic): ?>
            <?php
              $title = (string)(($topic['title_short'] ?? '') ?: ($topic['topic_title'] ?? ''));
              $long = (string)(($topic['title_long'] ?? '') ?: ($topic['topic_description'] ?? ''));
              $description = (string)($topic['learning_goal'] ?? $topic['topic_description'] ?? '');
              $keywords = [];
              if (!empty($topic['keywords_json'])) {
                  $decoded = json_decode((string)$topic['keywords_json'], true);
                  if (is_array($decoded)) $keywords = array_slice(array_filter(array_map('strval', $decoded)), 0, 10);
              }
            ?>
            <article class="learning-topic">
              <div class="learning-topic-title">
                <div>
                  <h3><?= teacher_h($title) ?></h3>
                  <?php if ($long && $long !== $title): ?><small><?= teacher_h($long) ?></small><?php endif; ?>
                </div>
              </div>
              <?php if ($description): ?><p><?= teacher_h($description) ?></p><?php endif; ?>

              <?php if (!empty($topic['subtopics'])): ?>
                <div class="learning-subtopics">
                  <?php foreach ($topic['subtopics'] as $subtopic): ?>
                    <span><?= teacher_h((string)(($subtopic['title_short'] ?? '') ?: ($subtopic['subtopic_title'] ?? ''))) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if ($keywords): ?>
                <div class="learning-keywords">
                  <?php foreach ($keywords as $keyword): ?><span><?= teacher_h($keyword) ?></span><?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php teacher_footer(); ?>
