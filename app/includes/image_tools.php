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

    $baseName = $safeKind . '-' . $entityId . '-' . date('Ymd-His') . '-' . substr(md5($prompt . microtime(true)), 0, 8);

    $originalFilename = $baseName . '.png';
    $webpFilename = $baseName . '.webp';

    $originalPath = $baseDir . '/' . $originalFilename;
    $webpPath = $baseDir . '/' . $webpFilename;

    $originalPublicPath = $baseUrl . '/' . $originalFilename;
    $webpPublicPath = $baseUrl . '/' . $webpFilename;

    if (!empty($image['b64_json'])) {
        $binary = base64_decode($image['b64_json'], true);
        if ($binary === false) {
            throw new RuntimeException('OpenAI Bild konnte nicht decodiert werden.');
        }
        file_put_contents($originalPath, $binary);
    } elseif (!empty($image['url'])) {
        $binary = elevaro_download_binary($image['url']);
        file_put_contents($originalPath, $binary);
    } else {
        throw new RuntimeException('OpenAI Bildantwort enthält weder b64_json noch URL.');
    }

    $frontendPath = $originalPublicPath;

    if (function_exists('imagecreatefrompng') && function_exists('imagewebp')) {
        $source = imagecreatefrompng($originalPath);

        if ($source !== false) {
            $width = imagesx($source);
            $height = imagesy($source);

            $maxWidth = 900;

            if ($width > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = (int)round($height * ($newWidth / $width));

                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagealphablending($resized, true);
                imagesavealpha($resized, true);

                imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

                if (imagewebp($resized, $webpPath, 82)) {
                    $frontendPath = $webpPublicPath;
                }

                imagedestroy($resized);
            } else {
                if (imagewebp($source, $webpPath, 82)) {
                    $frontendPath = $webpPublicPath;
                }
            }

            imagedestroy($source);
        }
    }

    return [
        'path' => $frontendPath,
        'original_path' => $originalPublicPath,
        'webp_path' => file_exists($webpPath) ? $webpPublicPath : null,
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
    $title = trim((string)($quiz['title'] ?? ''));
    $description = trim((string)($quiz['description'] ?? ''));
    $subject = trim((string)($quiz['subject_name'] ?? ''));
    $grade = (int)($quiz['grade'] ?? 0);
    $levelName = trim((string)($quiz['school_type_level_name'] ?? ''));

    if ($grade > 0 && $grade <= 4) {
        $audienceStyle = 'playful, warm, child-friendly educational illustration, simple shapes, cheerful colors, suitable for primary school children';
    } elseif ($grade > 0 && $grade <= 10) {
        $audienceStyle = 'clear modern educational illustration, friendly but not childish, simple visual metaphors, suitable for middle school students';
    } else {
        $audienceStyle = 'clean modern educational illustration, slightly more mature and professional, subtle colors, suitable for older students and vocational school students';
    }

    $levelText = $levelName !== ''
        ? "Level: {$levelName}."
        : ($grade > 0 ? "Grade: {$grade}." : '');

    return trim("
Topic: {$title}.
Description: {$description}.
Subject: {$subject}. {$levelText}

Create a full-bleed illustration for an educational quiz cover.
Style: {$audienceStyle}.
Bright but calm color palette, clear shapes, calm background, motivating learning atmosphere.

No text, no typography, no letters, no numbers, no logos.
No realistic people, no brand marks.

Do not create a card, poster, sticker, badge, icon, UI element, mockup, or framed artwork.
No borders, no frames, no outlines, no rounded edges, no drop shadows, no padding, no margins.
The illustration must fill the entire canvas edge-to-edge.
");
}

function elevaro_freepik_search_url(string $query): string
{
    return 'https://www.freepik.com/search?format=search&query=' . rawurlencode($query);
}
