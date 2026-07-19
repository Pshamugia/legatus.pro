<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Support\SignedVisitorToken;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Tests\TestCase;

class PublicChannelTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => null]);
    }

    public function test_public_json_routes_are_stateless_and_do_not_write_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $agent = $this->agent();

        $message = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Hello',
            'request_id' => fake()->uuid(),
        ])->assertOk()
            ->assertCookieMissing((string) config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');

        $token = $message->json('visitor_token');
        $this->withHeader('X-Legatus-Visitor-Token', $token)
            ->getJson("/demo/{$agent->slug}/history")
            ->assertOk()
            ->assertCookieMissing((string) config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');

        $this->postJson("/demo/{$agent->slug}/messages/{$message->json('message_id')}/feedback", [
            'feedback' => 'helpful',
            'visitor_token' => $token,
        ])->assertOk()
            ->assertCookieMissing((string) config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');

        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_only_public_json_routes_drop_cookie_session_and_csrf_middleware(): void
    {
        $this->get(route('landing'))->assertOk();
        $router = app('router');
        $statelessMiddleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
        ];

        foreach (['chat.message', 'chat.history', 'chat.feedback'] as $routeName) {
            $middleware = $router->gatherRouteMiddleware($router->getRoutes()->getByName($routeName));
            foreach ($statelessMiddleware as $excluded) {
                $this->assertNotContains($excluded, $middleware, "{$excluded} must stay off {$routeName}.");
            }
        }

        $protected = $router->gatherRouteMiddleware($router->getRoutes()->getByName('settings.update'));
        $this->assertContains(StartSession::class, $protected);
        $this->assertContains(ValidateCsrfToken::class, $protected);
    }

    public function test_history_rate_limit_uses_signed_visitor_with_an_ip_safety_ceiling(): void
    {
        $agent = $this->agent();
        $first = app(SignedVisitorToken::class)->issue($agent);
        $second = app(SignedVisitorToken::class)->issue($agent);

        $firstIp = $this->historyLimits($agent, $first['token'], '203.0.113.10');
        $sameVisitorOtherIp = $this->historyLimits($agent, $first['token'], '203.0.113.11');
        $otherVisitorSameIp = $this->historyLimits($agent, $second['token'], '203.0.113.10');

        $this->assertSame(120, $firstIp[0]->maxAttempts);
        $this->assertSame(360, $firstIp[1]->maxAttempts);
        $this->assertSame($firstIp[0]->key, $sameVisitorOtherIp[0]->key);
        $this->assertNotSame($firstIp[1]->key, $sameVisitorOtherIp[1]->key);
        $this->assertNotSame($firstIp[0]->key, $otherVisitorSameIp[0]->key);
        $this->assertSame($firstIp[1]->key, $otherVisitorSameIp[1]->key);
    }

    private function historyLimits(Agent $agent, string $token, string $ip): array
    {
        $request = Request::create(
            "/demo/{$agent->slug}/history",
            'GET',
            server: [
                'REMOTE_ADDR' => $ip,
                'HTTP_X_LEGATUS_VISITOR_TOKEN' => $token,
            ],
        );
        $route = new Route(['GET'], 'demo/{agent}/history', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return RateLimiter::limiter('public-history')($request);
    }

    private function agent(): Agent
    {
        return Agent::create([
            'name' => 'Legatus',
            'slug' => 'transport-agent',
            'business_name' => 'Transport Store',
            'is_active' => true,
        ]);
    }
}
