<?php

namespace App\Support;

use App\Models\Agent;
use Illuminate\Support\Str;

final class SignedVisitorToken
{
    private const VERSION = 'v1';

    private const LIFETIME_DAYS = 90;

    /**
     * @return array{visitor_id: string, token: string}
     */
    public function issue(Agent $agent): array
    {
        $visitorId = 'pub_'.Str::random(48);

        return [
            'visitor_id' => $visitorId,
            'token' => $this->sign($agent, $visitorId),
        ];
    }

    private function sign(Agent $agent, string $visitorId): string
    {
        $expiresAt = now()->addDays(self::LIFETIME_DAYS)->timestamp;
        $locator = $this->encode($visitorId);
        $payload = implode('.', [self::VERSION, $locator, $expiresAt]);
        $signature = $this->encode(hash_hmac('sha256', $this->signaturePayload($agent, $payload), $this->key(), true));

        return $payload.'.'.$signature;
    }

    public function resolve(Agent $agent, ?string $token): ?string
    {
        if (! is_string($token) || strlen($token) > 512) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION || ! ctype_digit($parts[2])) {
            return null;
        }

        [$version, $locator, $expiresAt, $providedSignature] = $parts;
        if ((int) $expiresAt < now()->timestamp) {
            return null;
        }

        $payload = implode('.', [$version, $locator, $expiresAt]);
        $expectedSignature = $this->encode(hash_hmac('sha256', $this->signaturePayload($agent, $payload), $this->key(), true));
        if (! hash_equals($expectedSignature, $providedSignature)) {
            return null;
        }

        $visitorId = $this->decode($locator);

        return is_string($visitorId) && preg_match('/^pub_[A-Za-z0-9]{48}$/', $visitorId) === 1
            ? $visitorId
            : null;
    }

    private function signaturePayload(Agent $agent, string $payload): string
    {
        return 'legatus-public-visitor|'.$agent->getKey().'|'.$payload;
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \LogicException('APP_KEY must be configured before public visitor tokens can be issued.');
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                $key = $decoded;
            }
        }

        return hash('sha256', 'legatus-public-channel|'.$key, true);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
