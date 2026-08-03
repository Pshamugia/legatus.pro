<?php

declare(strict_types=1);

$enabled = ($argv[1] ?? null) === 'on';
if (! $enabled && ($argv[1] ?? null) !== 'off') {
    fwrite(STDERR, "Usage: php scripts/set-paddle-billing-enforcement.php on|off\n");
    exit(1);
}

$path = dirname(__DIR__).'/.env';
$contents = file_get_contents($path);
if (! is_string($contents)) {
    fwrite(STDERR, "Could not read the server .env file.\n");
    exit(1);
}

if ($enabled) {
    $required = [
        'PADDLE_ENV' => 'live',
        'PADDLE_CLIENT_TOKEN' => 'live_',
        'PADDLE_API_KEY' => 'pdl_live_apikey_',
        'PADDLE_WEBHOOK_SECRET' => 'pdl_ntfset_',
        'PADDLE_PRICE_MONTHLY' => 'pri_',
        'PADDLE_PRICE_SIX_MONTHS' => 'pri_',
        'PADDLE_PRICE_YEARLY' => 'pri_',
    ];
    foreach ($required as $name => $prefix) {
        if (! preg_match('/^'.preg_quote($name, '/').'='.preg_quote($prefix, '/').'/m', $contents)) {
            fwrite(STDERR, "Cannot enable billing: {$name} is not configured for Live.\n");
            exit(1);
        }
    }
}

$line = 'PADDLE_BILLING_ENFORCED='.($enabled ? 'true' : 'false');
$pattern = '/^PADDLE_BILLING_ENFORCED=.*/m';
$contents = preg_match($pattern, $contents)
    ? (string) preg_replace($pattern, $line, $contents)
    : rtrim($contents).PHP_EOL.$line.PHP_EOL;

$temporary = $path.'.paddle.tmp';
if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "Could not update the server .env file.\n");
    exit(1);
}

echo 'Paddle billing enforcement is now '.($enabled ? 'ON' : 'OFF').".\n";
