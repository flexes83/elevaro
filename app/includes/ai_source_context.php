<?php

declare(strict_types=1);

function elevaro_ai_extract_urls(string $text): array
{
    preg_match_all('~https?://[^\s<>()"\']+~iu', $text, $matches);
    $urls = $matches[0] ?? [];
    $urls = array_map(static function (string $url): string {
        return rtrim($url, '.,;:!?)]}');
    }, $urls);
    return array_values(array_unique(array_filter($urls)));
}

function elevaro_ai_fetch_url_excerpt(string $url, int $maxChars = 7000): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ElevaroAIContextBot/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,text/plain,application/xhtml+xml'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return null;
    }

    if (stripos($type, 'pdf') !== false) {
        return "[PDF-Link wurde erkannt, aber serverseitig nicht ausgelesen: {$url}]";
    }

    $raw = preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', $raw);
    $raw = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $raw);
    $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', (string)$text);
    $text = trim((string)$text);

    if ($text === '') {
        return null;
    }

    return mb_substr($text, 0, $maxChars, 'UTF-8');
}

function elevaro_ai_upload_context_image(string $fieldName = 'source_image'): ?string
{
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Bildupload fehlgeschlagen.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Ungültiger Bildupload.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        throw new RuntimeException('Das Bild darf maximal 8 MB groß sein.');
    }

    $info = @getimagesize($tmp);
    if (!$info || empty($info['mime'])) {
        throw new RuntimeException('Bitte eine gültige Bilddatei hochladen.');
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $ext = $mimeToExt[$info['mime']] ?? null;
    if (!$ext) {
        throw new RuntimeException('Erlaubt sind JPG, PNG, WebP oder GIF.');
    }

    $relativeDir = '/uploads/ai_context/' . date('Ym');
    $absoluteDir = dirname(__DIR__, 2) . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
    }

    $filename = 'ctx_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absolutePath = $absoluteDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $absolutePath)) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden.');
    }

    return $relativeDir . '/' . $filename;
}

function elevaro_ai_collect_source_context(array $post, string $imageField = 'source_image'): array
{
    $extraPrompt = trim((string)($post['ai_extra_prompt'] ?? $post['learning_goal'] ?? ''));
    $sourceText = trim((string)($post['ai_source_text'] ?? ''));
    $sourceLinks = trim((string)($post['ai_source_links'] ?? ''));

    $urls = array_merge(
        elevaro_ai_extract_urls($sourceLinks),
        elevaro_ai_extract_urls($sourceText),
        elevaro_ai_extract_urls($extraPrompt)
    );
    $urls = array_slice(array_values(array_unique($urls)), 0, 4);

    $fetchedParts = [];
    foreach ($urls as $url) {
        $excerpt = elevaro_ai_fetch_url_excerpt($url);
        if ($excerpt) {
            $fetchedParts[] = "Quelle: {$url}\nAuszug:\n{$excerpt}";
        } else {
            $fetchedParts[] = "Quelle: {$url}\n[Diese Quelle konnte automatisch nicht zuverlässig ausgelesen werden. Relevanten Text bitte direkt ins Quellenfeld kopieren.]";
        }
    }

    $imagePath = elevaro_ai_upload_context_image($imageField);

    return [
        'extra_prompt' => $extraPrompt,
        'source_text' => $sourceText,
        'source_links' => implode("\n", $urls),
        'fetched_text' => trim(implode("\n\n---\n\n", $fetchedParts)),
        'image_path' => $imagePath,
    ];
}

function elevaro_ai_build_context_block(array $sourceContext): string
{
    $parts = [];

    if (!empty($sourceContext['extra_prompt'])) {
        $parts[] = "Zusätzliche Anweisung des Admins/Lehrers:\n" . trim((string)$sourceContext['extra_prompt']);
    }
    if (!empty($sourceContext['source_text'])) {
        $parts[] = "Bereitgestelltes Quellenmaterial / Artikeltext:\n" . trim((string)$sourceContext['source_text']);
    }
    if (!empty($sourceContext['fetched_text'])) {
        $parts[] = "Automatisch aus Links gelesene Auszüge:\n" . trim((string)$sourceContext['fetched_text']);
    }
    if (!empty($sourceContext['image_path'])) {
        $parts[] = "Hinweis: Es wurde ein Bild hochgeladen ({$sourceContext['image_path']}). Falls Informationen daraus wichtig sind, müssen sie zusätzlich im Quellenfeld beschrieben sein.";
    }

    if (!$parts) {
        return '';
    }

    return "\n\nZusätzliches Quellen- und Steuerungsmaterial:\n" . implode("\n\n---\n\n", $parts) . "\n\nVerbindliche Quellenregeln:\n- Verwende bei aktuellen, politischen oder strittigen Themen ausschließlich Informationen aus dem bereitgestellten Quellenmaterial.\n- Erfinde keine Daten, Entwicklungen, Personen, Statistiken, Zitate oder politischen Bewertungen.\n- Wenn aus den Quellen etwas nicht hervorgeht, formuliere es nicht als Tatsache.\n- Links allein sind nur dann als Quelle nutzbar, wenn daraus ein Auszug automatisch gelesen wurde oder der relevante Text zusätzlich eingefügt wurde.\n";
}
