<?php

namespace App\Services;

use RuntimeException;

class PaddleWebhookVerifier
{
    public function verify(string $rawBody, string $signatureHeader): void
    {
        $secret = (string) config('paddle.webhook_secret');
        if ($secret === '' || $rawBody === '' || $signatureHeader === '') {
            throw new RuntimeException('Paddle webhook verification is not configured.');
        }

        $parts = [];
        foreach (explode(';', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key][] = $value;
            }
        }

        $timestamp = $parts['ts'][0] ?? null;
        $signatures = $parts['h1'] ?? [];
        if (! ctype_digit((string) $timestamp) || $signatures === []) {
            throw new RuntimeException('Malformed Paddle signature.');
        }
        if (abs(time() - (int) $timestamp) > max(1, (int) config('paddle.webhook_tolerance'))) {
            throw new RuntimeException('Expired Paddle signature.');
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$rawBody, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new RuntimeException('Invalid Paddle signature.');
    }
}
