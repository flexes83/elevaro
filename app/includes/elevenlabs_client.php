<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function elevaro_elevenlabs_config(): array
{
    return elevaro_config('elevenlabs');
}

function elevaro_audio_upload_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/uploads/audio';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function elevaro_audio_public_path(string $filename): string
{
    return '/uploads/audio/' . ltrim($filename, '/');
}

function elevaro_generate_intro_audio_file(string $text, ?string $voiceId = null, ?string $modelId = null): array
{
    $text = trim($text);

    if ($text === '') {
        throw new RuntimeException('Kein Text für die Audio-Generierung vorhanden.');
    }

    $config = elevaro_elevenlabs_config();

    $apiKey = trim((string)($config['api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('ElevenLabs API-Key fehlt in /config/elevenlabs.php.');
    }

    $voiceId = trim((string)($voiceId ?: ($config['default_voice_id'] ?? '')));
    if ($voiceId === '') {
        throw new RuntimeException('ElevenLabs Voice-ID fehlt.');
    }

    $modelId = trim((string)($modelId ?: ($config['default_model_id'] ?? 'eleven_multilingual_v2')));
    $outputFormat = trim((string)($config['output_format'] ?? 'mp3_44100_128'));
    $baseUrl = rtrim((string)($config['base_url'] ?? 'https://api.elevenlabs.io/v1'), '/');

    $url = $baseUrl . '/text-to-speech/' . rawurlencode($voiceId);
    if ($outputFormat !== '') {
        $url .= '?output_format=' . rawurlencode($outputFormat);
    }

    $payload = [
        'text' => $text,
        'model_id' => $modelId,
        'voice_settings' => [
            'stability' => 0.55,
            'similarity_boost' => 0.75,
            'style' => 0.15,
            'use_speaker_boost' => true,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: audio/mpeg',
            'xi-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('ElevenLabs CURL-Fehler: ' . $curlError);
    }

    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    if ($status < 200 || $status >= 300) {
        $message = trim($body);
        $json = json_decode($body, true);
        if (is_array($json)) {
            $message = $json['detail']['message'] ?? $json['message'] ?? json_encode($json, JSON_UNESCAPED_UNICODE);
        }

        throw new RuntimeException('ElevenLabs API-Fehler (' . $status . '): ' . $message);
    }

    if ($body === '') {
        throw new RuntimeException('ElevenLabs lieferte keine Audiodaten.');
    }

    $filename = 'quiz_intro_' . date('Ymd_His') . '_' . substr(sha1($text . microtime(true)), 0, 10) . '.mp3';
    $absolute = elevaro_audio_upload_dir() . '/' . $filename;

    if (file_put_contents($absolute, $body) === false) {
        throw new RuntimeException('Audio-Datei konnte nicht gespeichert werden.');
    }

    return [
        'path' => elevaro_audio_public_path($filename),
        'absolute_path' => $absolute,
        'voice_id' => $voiceId,
        'model_id' => $modelId,
        'characters_used' => mb_strlen($text, 'UTF-8'),
        'headers' => $headers,
    ];
}


function elevaro_generate_audio_file(string $text, string $prefix = 'audio', ?string $voiceId = null, ?string $modelId = null): array
{
    // Generic wrapper for future audio types.
    $generated = elevaro_generate_intro_audio_file($text, $voiceId, $modelId);

    if ($prefix !== 'quiz_intro') {
        $oldAbsolute = $generated['absolute_path'];
        $ext = pathinfo($oldAbsolute, PATHINFO_EXTENSION) ?: 'mp3';
        $newFilename = preg_replace('/[^a-z0-9_-]+/i', '_', $prefix) . '_' . date('Ymd_His') . '_' . substr(sha1($text . microtime(true)), 0, 10) . '.' . $ext;
        $newAbsolute = elevaro_audio_upload_dir() . '/' . $newFilename;

        if (@rename($oldAbsolute, $newAbsolute)) {
            $generated['absolute_path'] = $newAbsolute;
            $generated['path'] = elevaro_audio_public_path($newFilename);
        }
    }

    return $generated;
}

function elevaro_generate_question_audio_file(string $text, int $questionId, ?string $voiceId = null, ?string $modelId = null): array
{
    return elevaro_generate_audio_file($text, 'question_' . $questionId, $voiceId, $modelId);
}

function elevaro_resolve_voice_for_quiz_question(array $quiz, array $question = []): ?string
{
    $config = elevaro_elevenlabs_config();
    $subject = mb_strtolower((string)($quiz['subject_name'] ?? ''), 'UTF-8');

    if (str_contains($subject, 'engl')) {
        return $config['english_voice_id'] ?? $config['default_english_voice_id'] ?? $config['default_voice_id'] ?? null;
    }

    return $config['german_soft_voice_id'] ?? $config['default_german_voice_id'] ?? $config['default_voice_id'] ?? null;
}
