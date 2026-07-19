<?php

namespace App\Providers;

use App\Models\Agent;
use App\Support\SignedVisitorToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-chat', function (Request $request): array {
            $agent = $request->route('agent');
            $agentKey = is_object($agent) ? $agent->getRouteKey() : (string) $agent;
            $key = $request->ip().'|'.$agentKey;

            return [
                Limit::perMinute(8)->by($key),
                Limit::perDay(100)->by($key),
            ];
        });

        RateLimiter::for('public-history', function (Request $request): array {
            $agent = $request->route('agent');
            $agentKey = is_object($agent) ? $agent->getRouteKey() : (string) $agent;
            $agentModel = $agent instanceof Agent
                ? $agent
                : Agent::query()->where('slug', $agentKey)->first();
            $providedToken = $request->header('X-Legatus-Visitor-Token') ?: $request->bearerToken();
            $visitorId = $agentModel instanceof Agent
                ? app(SignedVisitorToken::class)->resolve($agentModel, $providedToken)
                : null;
            $visitorKey = $visitorId === null
                ? 'anonymous:'.hash('sha256', (string) $request->ip())
                : hash('sha256', $visitorId);

            return [
                Limit::perMinute(120)->by($agentKey.'|visitor|'.$visitorKey),
                Limit::perMinute(360)->by($agentKey.'|ip|'.$request->ip()),
            ];
        });
    }
}
