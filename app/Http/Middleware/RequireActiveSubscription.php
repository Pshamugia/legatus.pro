<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->billingConfigured()) {
            return $next($request);
        }
        if (! app(TenantContext::class)->organization($request->user())->hasBillingAccess()) {
            return redirect()->route('billing.index');
        }

        return $next($request);
    }

    private function billingConfigured(): bool
    {
        return config('paddle.billing_enforced')
            && filled(config('paddle.client_token'))
            && collect(config('paddle.prices'))->filter()->count() === 3;
    }
}
