<?php

declare(strict_types=1);

const PADDLE_API_BASE = 'https://api.paddle.com';
const PADDLE_NOTIFICATION_ID = 'ntfset_01kz3v6r17dgdsbq8zc3k29kzq';
const PADDLE_WEBHOOK_URL = 'https://legatus.pro/webhooks/paddle';

$apiKey = getenv('PADDLE_KEY');
if (! is_string($apiKey) || ! str_starts_with($apiKey, 'pdl_live_apikey_')) {
    fwrite(STDERR, "Invalid Paddle Live API key.\n");
    exit(1);
}

$prices = [
    'PADDLE_PRICE_MONTHLY' => ['id' => 'pri_01kz3v6q5280d1v3t2vv8nrv2q', 'amount' => '3000', 'interval' => 'month', 'frequency' => 1],
    'PADDLE_PRICE_SIX_MONTHS' => ['id' => 'pri_01kz3v6qbvjwgkff1gp9t7teb8', 'amount' => '16200', 'interval' => 'month', 'frequency' => 6],
    'PADDLE_PRICE_YEARLY' => ['id' => 'pri_01kz3v6qjgmvrwgaxp2nd66ya5', 'amount' => '28800', 'interval' => 'year', 'frequency' => 1],
];

foreach ($prices as $price) {
    $data = paddleRequest('/prices/'.$price['id'], $apiKey);
    $valid = ($data['status'] ?? null) === 'active'
        && ($data['unit_price']['currency_code'] ?? null) === 'USD'
        && ($data['unit_price']['amount'] ?? null) === $price['amount']
        && ($data['billing_cycle']['interval'] ?? null) === $price['interval']
        && (int) ($data['billing_cycle']['frequency'] ?? 0) === $price['frequency']
        && ($data['trial_period']['interval'] ?? null) === 'day'
        && (int) ($data['trial_period']['frequency'] ?? 0) === 2;

    if (! $valid) {
        fwrite(STDERR, "Live price {$price['id']} does not match the approved billing plan.\n");
        exit(1);
    }
}

$notification = paddleRequest('/notification-settings/'.PADDLE_NOTIFICATION_ID, $apiKey);
$eventNames = array_column($notification['subscribed_events'] ?? [], 'name');
$requiredEvents = [
    'subscription.activated',
    'subscription.canceled',
    'subscription.created',
    'subscription.paused',
    'subscription.resumed',
    'subscription.updated',
];
$webhookSecret = $notification['endpoint_secret_key'] ?? null;
if (($notification['active'] ?? false) !== true
    || ($notification['destination'] ?? null) !== PADDLE_WEBHOOK_URL
    || array_diff($requiredEvents, $eventNames) !== []
    || ! is_string($webhookSecret)
    || $webhookSecret === '') {
    fwrite(STDERR, "The Paddle Live webhook destination is incomplete.\n");
    exit(1);
}

$tokens = paddleRequest('/client-tokens?status=active', $apiKey, true);
$clientToken = null;
foreach ($tokens as $token) {
    if (($token['name'] ?? null) === 'Legatus Live Checkout' && is_string($token['token'] ?? null)) {
        $clientToken = $token['token'];
        break;
    }
}
if (! is_string($clientToken) || ! str_starts_with($clientToken, 'live_')) {
    fwrite(STDERR, "Could not find the active Legatus Live Checkout client token.\n");
    exit(1);
}

$values = [
    // Turn this on only after the first real checkout and webhook are confirmed.
    'PADDLE_BILLING_ENFORCED' => 'false',
    'PADDLE_ENV' => 'live',
    'PADDLE_CLIENT_TOKEN' => $clientToken,
    'PADDLE_API_KEY' => $apiKey,
    'PADDLE_WEBHOOK_SECRET' => $webhookSecret,
    'PADDLE_WEBHOOK_TOLERANCE' => '300',
];
foreach ($prices as $name => $price) {
    $values[$name] = $price['id'];
}

$path = dirname(__DIR__).'/.env';
$contents = file_get_contents($path);
if (! is_string($contents)) {
    fwrite(STDERR, "Could not read the server .env file.\n");
    exit(1);
}

foreach ($values as $name => $value) {
    $pattern = '/^'.preg_quote($name, '/').'=.*/m';
    $line = $name.'='.$value;
    $contents = preg_match($pattern, $contents)
        ? (string) preg_replace($pattern, $line, $contents)
        : rtrim($contents).PHP_EOL.$line.PHP_EOL;
}

$temporary = $path.'.paddle.tmp';
if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "Could not update the server .env file.\n");
    exit(1);
}

echo "Paddle Live checkout and webhook configured safely. Billing enforcement remains OFF for the first real test.\n";

function paddleRequest(string $path, string $apiKey, bool $list = false): array
{
    $curl = curl_init(PADDLE_API_BASE.$path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$apiKey,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $decoded = json_decode((string) $response, true);
    $data = $decoded['data'] ?? null;
    if ($status !== 200 || ! is_array($data)) {
        throw new RuntimeException("Paddle API request failed ({$status}): {$error}");
    }

    return $data;
}
