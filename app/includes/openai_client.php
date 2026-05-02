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

    $ch = curl_init('https://api.openai.com/v1/chat/completions');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['api_key'],
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
