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
        $frameAncestors = $widget ? $this->widgetFrameAncestors($request) : "'none'";

        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; frame-ancestors {$frameAncestors}");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Resource-Policy', $widget ? 'cross-origin' : 'same-origin');

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
        $raw = is_array($tenantSources) && $tenantSources !== []
            ? implode(' ', $tenantSources)
            : (string) config('legatus.widget_frame_ancestors', '*');
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
}
