<?php

/*
 * Sends an example event the way MarketHub does: signs the file's bytes as they are.
 *
 *   MARKETPLACE_WEBHOOK_SECRET=secret php send-event.php http://localhost/api/webhooks/marketplace order-paid.json
 *
 * Requires PHP 8.2+ with the curl extension.
 */

declare(strict_types=1);

[$script, $url, $file] = $argv + [null, null, null];

if ($url === null || $file === null) {
    fwrite(STDERR, "Usage: MARKETPLACE_WEBHOOK_SECRET=... php {$script} <webhook-url> <event.json>\n");
    exit(2);
}

$secret = getenv('MARKETPLACE_WEBHOOK_SECRET');
if ($secret === false || $secret === '') {
    fwrite(STDERR, "MARKETPLACE_WEBHOOK_SECRET is not set\n");
    exit(2);
}

$body = file_get_contents($file);
if ($body === false) {
    fwrite(STDERR, "Cannot read {$file}\n");
    exit(2);
}

$event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Webhook-Id: ' . $event['id'],
        'X-Webhook-Timestamp: ' . $timestamp,
        'X-Webhook-Signature: sha256=' . $signature,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);

$response = curl_exec($ch);
if ($response === false) {
    fwrite(STDERR, 'Request failed: ' . curl_error($ch) . "\n");
    exit(1);
}

echo 'HTTP ' . curl_getinfo($ch, CURLINFO_RESPONSE_CODE) . "\n" . $response . "\n";
