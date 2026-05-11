<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$logPath = __DIR__ . '/payment-webhooks.log.php';

if (!file_exists($logPath)) {
    @file_put_contents($logPath, "<?php exit; ?>\n", LOCK_EX);
}

$line = json_encode([
    'received_at' => date('c'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'payload' => json_decode($raw, true) ?: $raw,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

@file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

echo json_encode([
    'ok' => true,
]);
