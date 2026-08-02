<?php

$key = getenv('PADDLE_KEY');
if (! is_string($key) || ! str_starts_with($key, 'pdl_sdbx_apikey_')) {
    fwrite(STDERR, "Invalid Paddle Sandbox API key.\n");
    exit(1);
}

$curl = curl_init('https://sandbox-api.paddle.com/notification-settings/ntfset_01kz24ax35dzm0a9zfsedvqzb1');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.$key,
        'Accept: application/json',
    ],
]);
$response = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

$data = json_decode((string) $response, true);
$secret = $data['data']['endpoint_secret_key'] ?? null;
if ($status !== 200 || ! is_string($secret) || $secret === '') {
    fwrite(STDERR, "Could not retrieve the Paddle webhook secret.\n");
    exit(1);
}

$values = [
    'PADDLE_BILLING_ENFORCED' => 'false',
    'PADDLE_ENV' => 'sandbox',
    'PADDLE_CLIENT_TOKEN' => 'test_da89f9be21650607d2bc4d899ca',
    'PADDLE_WEBHOOK_SECRET' => $secret,
    'PADDLE_WEBHOOK_TOLERANCE' => '300',
    'PADDLE_PRICE_MONTHLY' => 'pri_01kz242583ebjkw0tm71xm0pwy',
    'PADDLE_PRICE_SIX_MONTHS' => 'pri_01kz2425gd1yxdg0ftxhjbgsjp',
    'PADDLE_PRICE_YEARLY' => 'pri_01kz2425px9ff9efk411dyqy27',
];

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
        ? preg_replace($pattern, $line, $contents)
        : rtrim($contents).PHP_EOL.$line.PHP_EOL;
}

$temporary = $path.'.paddle.tmp';
if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "Could not update the server .env file.\n");
    exit(1);
}

echo "Paddle Sandbox environment configured safely.\n";
