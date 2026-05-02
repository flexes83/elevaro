<?php

require_once __DIR__ . '/openai_client.php';

function elevaro_generate_and_store_image(string $prompt, string $kind, int $entityId): array
{
    $prompt = trim($prompt);

    if ($prompt === '') {
        throw new RuntimeException('Bildprompt fehlt.');
    }

    $image = elevaro_openai_generate_image($prompt);

    $safeKind = preg_replace('/[^a-z0-9_-]+/i', '-', $kind) ?: 'image';
    $baseDir = dirname(__DIR__, 2) . '/uploads/ai/' . $safeKind;
    $baseUrl = '/uploads/ai/' . $safeKind;

    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden: ' . $baseDir);
    }

    $filename = $safeKind . '-' . $entityId . '-' . date('Ymd-His') . '-' . substr(md5($prompt . microtime(true)), 0, 8) . '.png';
    $fullPath = $baseDir . '/' . $filename;
    $publicPath = $baseUrl . '/' . $filename;

    if (!empty($image['b64_json'])) {
        $binary = base64_decode($image['b64_json'], true);
        if ($binary === false) {
            throw new RuntimeException('OpenAI Bild konnte nicht decodiert werden.');
        }
        file_put_contents($fullPath, $binary);
    } elseif (!empty($image['url'])) {
        $binary = elevaro_download_binary($image['url']);
        file_put_contents($fullPath, $binary);
    } else {
        throw new RuntimeException('OpenAI Bildantwort enthält weder b64_json noch URL.');
    }

    return [
        'path' => $publicPath,
        'prompt' => $prompt,
        'revised_prompt' => $image['revised_prompt'] ?? null,
    ];
}

function elevaro_download_binary(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    $binary = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($binary === false || $error || $status >= 400) {
        throw new RuntimeException('Bilddownload fehlgeschlagen: ' . ($error ?: 'HTTP ' . $status));
    }

    return $binary;
}

function elevaro_card_image_prompt(array $quiz): string
{
    $title = $quiz['title'] ?? '';
    $description = $quiz['description'] ?? '';
    $subject = $quiz['subject_name'] ?? '';
    $grade = $quiz['grade'] ?? '';

    return trim("Freundliche moderne flache Illustration für eine Lernquiz-Karte. Thema: {$title}. Beschreibung: {$description}. Fach: {$subject}, Klasse {$grade}. Stil: schülergerecht, motivierend, nicht babyhaft, helle Farben, klare Formen, kein Text im Bild, keine Logos, keine realistischen Personen, quadratisches Motiv mit ruhigem Hintergrund.");
}

function elevaro_freepik_search_url(string $query): string
{
    return 'https://www.freepik.com/search?format=search&query=' . rawurlencode($query);
}
