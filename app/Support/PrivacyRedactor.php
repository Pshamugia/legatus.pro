<?php

namespace App\Support;

final class PrivacyRedactor
{
    private const SENSITIVE_KEYS = [
        'email',
        'phone',
        'contact',
        'contact_details',
    ];

    public static function text(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email redacted]', $text) ?? $text;

        return preg_replace_callback('/(?<!\w)(\+?\d[\d\s()\-]{7,}\d)(?!\w)/u', function (array $match): string {
            return self::phoneDigits($match[1]) !== null ? '[phone redacted]' : $match[1];
        }, $text) ?? $text;
    }

    public static function structured(array $data): array
    {
        return self::redactValue($data);
    }

    /**
     * Return keyed, non-reversible contact fingerprints so a redacted message
     * can still prove which exact contact values the customer supplied.
     */
    public static function contactEvidence(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text, $emailMatches);
        preg_match_all('/(?<!\w)(\+?\d[\d\s()\-]{7,}\d)(?!\w)/u', $text, $phoneMatches);

        $emails = collect($emailMatches[0] ?? [])
            ->map(fn (string $email) => self::contactHash(strtolower(trim($email))))
            ->unique()
            ->values()
            ->all();
        $phones = collect($phoneMatches[1] ?? [])
            ->map(fn (string $phone) => self::phoneDigits($phone))
            ->filter()
            ->map(fn (string $digits) => self::contactHash($digits))
            ->unique()
            ->values()
            ->all();

        return ['email_hashes' => $emails, 'phone_hashes' => $phones];
    }

    public static function contactEvidenceMatches(array $evidence, ?string $email, ?string $phone): bool
    {
        $emailHashes = collect($evidence['email_hashes'] ?? [])->flatten()->filter();
        $phoneHashes = collect($evidence['phone_hashes'] ?? [])->flatten()->filter();
        $emailMatches = ! $email || $emailHashes->contains(self::contactHash(strtolower(trim($email))));
        $phoneDigits = $phone ? (preg_replace('/\D/', '', $phone) ?? '') : null;
        $phoneMatches = ! $phone || $phoneHashes->contains(self::contactHash($phoneDigits));

        return $emailMatches && $phoneMatches && ($email !== null || $phone !== null);
    }

    public static function toolTrace(array $calls): array
    {
        $calls = self::structured($calls);
        foreach ($calls as &$call) {
            if (($call['name'] ?? null) !== 'create_lead') {
                continue;
            }
            foreach (['name', 'email', 'phone'] as $key) {
                if (isset($call['arguments'][$key]) && $call['arguments'][$key] !== '') {
                    $call['arguments'][$key] = '[redacted]';
                }
            }
        }

        return $calls;
    }

    private static function redactValue(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $value[$childKey] = self::redactValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($key !== null && in_array(strtolower($key), self::SENSITIVE_KEYS, true) && trim($value) !== '') {
            return '[redacted]';
        }

        return self::text($value);
    }

    private static function contactHash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private static function phoneDigits(string $value): ?string
    {
        $candidate = trim($value);
        if (preg_match('/^\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}(?:[ T]\d{1,2})?$/', $candidate)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $candidate) ?? '';

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : null;
    }
}
