<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class WidgetLocaleResolver
{
    private const SUPPORTED_LOCALES = ['ka', 'en'];

    public function resolve(Request $request): string
    {
        $requestedLocale = strtolower(trim((string) $request->query('lang')));
        if (in_array($requestedLocale, self::SUPPORTED_LOCALES, true)) {
            return $requestedLocale;
        }

        $country = $this->configuredCountry($request) ?? $this->countryForIp($request->ip());
        if ($country !== null) {
            return $country === 'GE' ? 'ka' : 'en';
        }

        // Unknown locations must not be inferred from the browser language:
        // visitors can prefer Georgian while being outside Georgia (and vice versa).
        return 'en';
    }

    private function configuredCountry(Request $request): ?string
    {
        $serverKey = trim((string) config('legatus.widget_country_server_key'));
        if ($serverKey !== '') {
            // HTTP_* server values originate from request headers. Treat them like
            // any other edge header instead of implicitly trusting the client.
            $serverValueIsAHeader = str_starts_with(strtoupper($serverKey), 'HTTP_');
            $country = ! $serverValueIsAHeader || $this->isTrustedHeaderSource($request)
                ? $this->normalizeCountry($request->server($serverKey))
                : null;
            if ($country !== null) {
                return $country;
            }
        }

        // Only trust a country header when the deployment explicitly opts in.
        // The edge/origin must strip customer-supplied copies of that header.
        $header = trim((string) config('legatus.widget_country_header'));
        if ($header === '' || preg_match('/^[A-Za-z0-9-]+$/', $header) !== 1) {
            return null;
        }

        return $this->isTrustedHeaderSource($request)
            ? $this->normalizeCountry($request->header($header))
            : null;
    }

    private function countryForIp(?string $ip): ?string
    {
        if (! is_string($ip) || filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return null;
        }

        $urlTemplate = trim((string) config('legatus.widget_country_lookup_url'));
        $lookupUrl = $this->lookupUrl($urlTemplate, $ip);
        if ($lookupUrl === null) {
            return null;
        }

        $fingerprint = $this->ipFingerprint($ip);
        $cacheKey = $fingerprint === null
            ? null
            : 'widget-country:v1:'.hash('sha256', $urlTemplate).':'.$fingerprint;
        if ($cacheKey !== null) {
            try {
                $cached = Cache::get($cacheKey);
                if (is_array($cached) && array_key_exists('country', $cached)) {
                    return $this->normalizeCountry($cached['country']);
                }
            } catch (Throwable) {
                // Locale resolution must keep working if the application cache is down.
            }
        }

        $country = null;
        try {
            $response = Http::acceptJson()
                ->connectTimeout(1)
                ->timeout(2)
                ->withoutRedirecting()
                ->get($lookupUrl);
            $country = $response->successful()
                ? $this->normalizeCountry($response->json('country'))
                : null;
        } catch (Throwable) {
            // A lookup outage is non-fatal and falls back to English.
        }

        $cacheHours = max(1, min(720, (int) config('legatus.widget_country_cache_hours', 24)));
        $negativeCacheMinutes = max(1, min(60, (int) config('legatus.widget_country_negative_cache_minutes', 10)));
        if ($cacheKey !== null) {
            try {
                Cache::put(
                    $cacheKey,
                    ['country' => $country],
                    $country === null ? now()->addMinutes($negativeCacheMinutes) : now()->addHours($cacheHours),
                );
            } catch (Throwable) {
                // Cache availability must not make the public widget unavailable.
            }
        }

        return $country;
    }

    private function isTrustedHeaderSource(Request $request): bool
    {
        $remoteAddress = $request->server('REMOTE_ADDR');
        if (! is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $trustedProxies = preg_split(
            '/[\s,]+/',
            trim((string) config('legatus.widget_country_trusted_proxies')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        if ($trustedProxies === []) {
            return false;
        }

        try {
            return IpUtils::checkIp($remoteAddress, $trustedProxies);
        } catch (Throwable) {
            return false;
        }
    }

    private function lookupUrl(string $template, string $ip): ?string
    {
        if ($template === '' || substr_count($template, '{ip}') !== 1) {
            return null;
        }

        $url = str_replace('{ip}', rawurlencode($ip), $template);
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    private function ipFingerprint(string $ip): ?string
    {
        $key = (string) config('app.key');

        // An unkeyed IPv4 hash is cheap to reverse. Without APP_KEY, skip the
        // cache rather than persist a stable visitor identifier.
        return $key !== '' ? hash_hmac('sha256', $ip, $key) : null;
    }

    private function normalizeCountry(mixed $country): ?string
    {
        if (! is_string($country)) {
            return null;
        }

        $country = strtoupper(trim($country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 && ! in_array($country, ['XX', 'T1'], true)
            ? $country
            : null;
    }
}
