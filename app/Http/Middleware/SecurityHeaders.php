<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $request->attributes->set('csp_nonce', $nonce);
        $response = $next($request);
        $widget = $request->is('widget/*');
        $billing = $request->routeIs('billing.index', 'ai-reels.index');
        $whatsAppSignup = $request->routeIs('channels.whatsapp.connect');
        $frameAncestors = $widget ? $this->widgetFrameAncestors($request) : "'none'";
        $paddleSources = $billing ? ' https://cdn.paddle.com https://*.paddle.com https://*.paddle.io' : '';

        $metaSources = $whatsAppSignup
            ? ' https://connect.facebook.net https://www.facebook.com https://web.facebook.com'
            : '';
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}'{$paddleSources}{$metaSources}; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'{$paddleSources}{$metaSources}; frame-src 'self'{$paddleSources}{$metaSources}; base-uri 'self'; form-action 'self'; object-src 'none'; frame-ancestors {$frameAncestors}");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Resource-Policy', ($widget || $request->routeIs('ai-reels.media')) ? 'cross-origin' : 'same-origin');

        if (! $widget) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function widgetFrameAncestors(Request $request): string
    {
        $agent = $request->route('agent');
        $tenantSources = is_object($agent) ? data_get($agent, 'settings.widget_allowed_origins') : null;
        $legacyWebsiteSources = is_object($agent)
            ? $this->websiteOrigins(data_get($agent, 'settings.website'))
            : [];
        $raw = match (true) {
            is_array($tenantSources) && $tenantSources !== [] => implode(' ', $tenantSources),
            $legacyWebsiteSources !== [] => implode(' ', $legacyWebsiteSources),
            is_object($agent) && data_get($agent, 'slug') !== 'legatus-demo' => '',
            default => (string) config('legatus.widget_frame_ancestors', '*'),
        };
        $configured = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $sources = collect($configured)
            ->filter()
            ->map(function (string $source): ?string {
                if ($source === '*') {
                    return '*';
                }
                if (in_array($source, ['self', "'self'"], true)) {
                    return "'self'";
                }

                return preg_match('#^https?://(?:\*\.)?[a-z0-9.-]+(?::\d{1,5})?$#i', $source)
                    ? $source
                    : null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($sources->contains('*')) {
            return '*';
        }

        return $sources->isEmpty() ? "'none'" : $sources->implode(' ');
    }

    /** @return list<string> */
    private function websiteOrigins(mixed $website): array
    {
        if (! is_string($website) || $website === '') {
            return [];
        }

        $parts = parse_url($website);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return [];
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $alternateHost = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;

        return array_values(array_unique([
            "{$scheme}://{$host}{$port}",
            "{$scheme}://{$alternateHost}{$port}",
        ]));
    }
}
