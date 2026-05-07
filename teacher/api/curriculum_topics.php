<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    $teacherId = teacher_current_user_id();
    $classId = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
    $class = elevaro_teacher_ai_class_for_teacher($classId, $teacherId);
    $topics = elevaro_teacher_ai_curriculum_topics_for_class($class);
    $domains = [];
    foreach ($topics as $topic) {
        $domainKey = (string)(($topic['domain_key'] ?? '') ?: ($topic['domain_title'] ?? 'Allgemein'));
        if (!isset($domains[$domainKey])) {
            $domains[$domainKey] = ['key' => $domainKey, 'title' => (string)(($topic['domain_title'] ?? '') ?: 'Allgemein'), 'topics' => []];
        }
        $domains[$domainKey]['topics'][] = [
            'id' => (int)$topic['id'],
            'title' => (string)(($topic['title_short'] ?? '') ?: ($topic['topic_title'] ?? 'Thema')),
            'title_long' => (string)($topic['title_long'] ?? ''),
            'description' => (string)($topic['topic_description'] ?? ''),
            'subtopics' => array_map(static fn($sub) => [
                'id' => (int)$sub['id'],
                'title' => (string)(($sub['title_short'] ?? '') ?: ($sub['subtopic_title'] ?? 'Unterthema')),
                'title_long' => (string)($sub['title_long'] ?? ''),
            ], (array)($topic['subtopics'] ?? [])),
        ];
    }
    elevaro_teacher_ai_json_response(['ok' => true, 'domains' => array_values($domains), 'count' => count($topics)]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
