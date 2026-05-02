<?php

require_once __DIR__ . '/config.php';

function elevaro_openai_chat_json(array $messages, array $schema, float $temperature = 0.35): array
{
    $config = elevaro_config('openai');

    if (empty($config['api_key'])) {
        throw new RuntimeException('OpenAI API key missing. Create /config/openai.php.');
    }

    $payload = [
        'model' => $config['model'] ?? 'gpt-4.1-mini',
        'messages' => $messages,
        'temperature' => $temperature,
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'elevaro_generation',
                'strict' => true,
                'schema' => $schema
            ]
        ]
    ];

    $raw = elevaro_openai_request('https://api.openai.com/v1/chat/completions', $payload, $config['api_key'], 120);
    $data = json_decode($raw, true);
    $content = $data['choices'][0]['message']['content'] ?? null;

    if (!$content) {
        throw new RuntimeException('OpenAI response did not contain content.');
    }

    $json = json_decode($content, true);

    if (!is_array($json)) {
        throw new RuntimeException('OpenAI response was not valid JSON.');
    }

    return [
        'json' => $json,
        'raw' => $raw,
        'content' => $content,
    ];
}

function elevaro_openai_generate_image(string $prompt, string $size = '1024x1024'): array
{
    $config = elevaro_config('openai');

    if (empty($config['api_key'])) {
        throw new RuntimeException('OpenAI API key missing. Create /config/openai.php.');
    }

    $payload = [
        'model' => $config['image_model'] ?? 'gpt-image-1',
        'prompt' => $prompt,
        'size' => $size,
        'n' => 1,
    ];

    $raw = elevaro_openai_request('https://api.openai.com/v1/images/generations', $payload, $config['api_key'], 180);
    $data = json_decode($raw, true);
    $image = $data['data'][0] ?? null;

    if (!$image) {
        throw new RuntimeException('OpenAI image response did not contain image data.');
    }

    return [
        'raw' => $raw,
        'b64_json' => $image['b64_json'] ?? null,
        'url' => $image['url'] ?? null,
        'revised_prompt' => $image['revised_prompt'] ?? null,
    ];
}

function elevaro_openai_request(string $url, array $payload, string $apiKey, int $timeout = 120): string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $error) {
        throw new RuntimeException('OpenAI request failed: ' . $error);
    }

    $data = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
        $message = $data['error']['message'] ?? $raw;
        throw new RuntimeException('OpenAI API error: ' . $message);
    }

    return $raw;
}
