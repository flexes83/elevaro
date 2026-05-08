<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function elevaro_quiz_card_can_edit(): bool
{
    return auth_is_admin();
}

/**
 * Server-side fallback renderer for quiz cards.
 * JS lists should use assets/js/quiz-card.js with the same data shape.
 */
function elevaro_render_quiz_card(array $item): void
{
    $subjectCode = strtolower((string)($item['subject_code'] ?? ''));
    $emojiMap = [
        'mathe' => '➗',
        'mathematik' => '➗',
        'deutsch' => '📖',
        'englisch' => '🇬🇧',
        'geographie' => '🗺️',
        'erdkunde' => '🗺️',
        'biologie' => '🌱',
        'bio' => '🌱',
        'physik' => '🧲',
        'chemie' => '⚗️',
    ];

    $emoji = $item['theme_emoji'] ?? $item['subject_icon'] ?? ($emojiMap[$subjectCode] ?? '🎯');
    $title = $item['quiz_title'] ?? $item['title'] ?? $item['topic_title'] ?? 'Quiz';
    $description = $item['quiz_description'] ?? $item['description'] ?? $item['topic_description'] ?? 'Starte ein kurzes Quiz mit direktem Feedback.';
    $quizKey = $item['quiz_key'] ?? '';
    $imagePath = $item['image_path'] ?? '';
    $imageStatus = strtolower(trim((string)($item['image_status'] ?? '')));
    // Zeige vorhandene Bilder auch dann, wenn sie aus dem Wizard noch als "draft" markiert sind.
    // Versteckt werden nur eindeutig nicht nutzbare/abgelehnte Statuswerte.
    $hasImage = $imagePath && !in_array($imageStatus, ['none','failed','error','rejected'], true);
    $tags = [];

    if (!empty($item['tag_names'])) {
        $tags = array_filter(array_map('trim', explode(',', (string)$item['tag_names'])));
    }

    $canEdit = elevaro_quiz_card_can_edit();
    ?>
    <article class="elevaro-quiz-card">
      <div class="elevaro-quiz-card-media">
        <?php if ($hasImage): ?>
          <img class="elevaro-quiz-card-img" src="<?= htmlspecialchars((string)$imagePath, ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php else: ?>
          <span class="elevaro-quiz-card-fallback"><?= htmlspecialchars((string)$emoji, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>

        <span class="elevaro-quiz-card-badge">
          <?= htmlspecialchars((string)($item['subject_name'] ?? 'Quiz'), ENT_QUOTES, 'UTF-8') ?>
          ·
          <?= htmlspecialchars((string)($item['topic_title'] ?? 'Lernbereich'), ENT_QUOTES, 'UTF-8') ?>
        </span>

        <span class="elevaro-quiz-card-donut is-empty" title="Noch nicht gespielt">
          <span class="elevaro-quiz-card-donut-label"></span>
        </span>
      </div>

      <div class="elevaro-quiz-card-body">
        <h3 class="elevaro-quiz-card-title"><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></h3>
        <p class="elevaro-quiz-card-description"><?= htmlspecialchars((string)$description, ENT_QUOTES, 'UTF-8') ?></p>

        <?php if ($tags): ?>
          <div class="elevaro-quiz-card-tags">
            <?php foreach (array_slice($tags, 0, 4) as $tag): ?>
              <span class="elevaro-quiz-card-tag"><?= htmlspecialchars((string)$tag, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="elevaro-quiz-card-footer">
          <a class="btn btn-primary" href="/quiz.php?key=<?= urlencode((string)$quizKey) ?>">Quiz starten</a>
          <span class="elevaro-quiz-card-meta">noch nicht gespielt</span>
        </div>
      </div>

      <?php if ($canEdit && !empty($item['quiz_id'])): ?>
        <a class="elevaro-quiz-card-admin" href="/admin/quiz_questions.php?quiz_id=<?= (int)$item['quiz_id'] ?>">bearbeiten</a>
      <?php endif; ?>
    </article>
    <?php
}
