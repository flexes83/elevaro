<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function elevaro_stripe_config(): array
{
    return elevaro_config('stripe');
}

function elevaro_stripe_request(string $method, string $endpoint, array $params = []): array
{
    $config = elevaro_stripe_config();
    $secret = trim((string)($config['secret_key'] ?? ''));

    if ($secret === '') {
        throw new RuntimeException('Stripe Secret Key fehlt in /config/stripe.php.');
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');
    $ch = curl_init();

    $headers = [
        'Authorization: Bearer ' . $secret,
    ];

    if (strtoupper($method) === 'GET' && $params) {
        $url .= '?' . http_build_query($params);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $error) {
        throw new RuntimeException('Stripe Request fehlgeschlagen: ' . $error);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Stripe Antwort war kein JSON.');
    }

    if ($status < 200 || $status >= 300) {
        $message = $data['error']['message'] ?? $raw;
        throw new RuntimeException('Stripe Fehler: ' . $message);
    }

    return $data;
}

function elevaro_create_student_checkout_session(array $user): string
{
    $config = elevaro_stripe_config();
    $priceId = trim((string)($config['price_student_monthly'] ?? ''));

    if ($priceId === '') {
        throw new RuntimeException('Stripe Price-ID für Schüler-Abo fehlt.');
    }

    $params = [
        'mode' => 'subscription',
        'success_url' => $config['success_url'] ?? 'https://elevaro.app/billing_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $config['cancel_url'] ?? 'https://elevaro.app/paywall.php?cancelled=1',
        'line_items[0][price]' => $priceId,
        'line_items[0][quantity]' => 1,
        'metadata[user_id]' => (string)$user['id'],
        'client_reference_id' => (string)$user['id'],
        'customer_email' => $user['email'] ?? '',
        'subscription_data[metadata][user_id]' => (string)$user['id'],
        'allow_promotion_codes' => 'true',
    ];

    $session = elevaro_stripe_request('POST', 'checkout/sessions', $params);

    if (empty($session['url'])) {
        throw new RuntimeException('Stripe Checkout URL fehlt.');
    }

    return (string)$session['url'];
}
