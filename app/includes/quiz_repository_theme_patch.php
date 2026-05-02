<?php
// Patch idea: include visual fields in quiz queries.
// In elevaro_get_quiz_by_key(), add these columns:
// q.image_path, q.image_status, q.theme_color_1, q.theme_color_2, q.theme_emoji
//
// In recommendation/list UIs use:
// style="--quiz-c1: <?= h($quiz['theme_color_1']) ?>; --quiz-c2: <?= h($quiz['theme_color_2']) ?>"
// and fallback visual emoji if no approved image exists.
