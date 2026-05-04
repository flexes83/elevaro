<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/config.php';

$config = elevaro_config('stripe');
$payload = file_get_contents('php://input') ?: '';
$event = json_decode($payload, true);

if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$type = $event['type'] ?? '';
$object = $event['data']['object'] ?? [];

try {
    $pdo = elevaro_db();

    if ($type === 'checkout.session.completed') {
        $userId = (int)($object['metadata']['user_id'] ?? $object['client_reference_id'] ?? 0);
        if ($userId) {
            $pdo->prepare("
                UPDATE auth_users
                SET plan = 'premium',
                    stripe_customer_id = :customer,
                    stripe_subscription_id = :subscription,
                    plan_expires_at = NULL
                WHERE id = :user_id
            ")->execute([
                'customer' => $object['customer'] ?? null,
                'subscription' => $object['subscription'] ?? null,
                'user_id' => $userId,
            ]);
        }
    }

    if (in_array($type, ['customer.subscription.deleted','customer.subscription.paused'], true)) {
        $subscriptionId = (string)($object['id'] ?? '');
        if ($subscriptionId !== '') {
            $pdo->prepare("
                UPDATE auth_users
                SET plan = 'free'
                WHERE stripe_subscription_id = :subscription
            ")->execute(['subscription' => $subscriptionId]);
        }
    }

    if ($type === 'customer.subscription.updated') {
        $subscriptionId = (string)($object['id'] ?? '');
        $status = (string)($object['status'] ?? '');
        if ($subscriptionId !== '') {
            $plan = in_array($status, ['active','trialing'], true) ? 'premium' : 'free';
            $pdo->prepare("
                UPDATE auth_users
                SET plan = :plan
                WHERE stripe_subscription_id = :subscription
            ")->execute([
                'plan' => $plan,
                'subscription' => $subscriptionId,
            ]);
        }
    }

    echo 'ok';
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
}
