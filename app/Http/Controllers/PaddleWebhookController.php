<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PaddleSubscription;
use App\Services\PaddleWebhookVerifier;
use App\Services\ReelCreditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaddleWebhookController extends Controller
{
    public function __invoke(Request $request, PaddleWebhookVerifier $verifier, ReelCreditService $credits): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('Paddle-Signature', '');
        if ($rawBody === '' || $signature === '') {
            return response()->json(['error' => 'Missing signature or body.'], 400);
        }

        try {
            $verifier->verify($rawBody, $signature);
            $event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
            $this->process($event, $credits);

            return response()->json(['received' => true]);
        } catch (Throwable $exception) {
            Log::error('Paddle webhook failed.', ['error' => $exception->getMessage()]);

            return response()->json(['error' => 'Webhook processing failed.'], 500);
        }
    }

    private function process(array $event, ReelCreditService $credits): void
    {
        $eventId = (string) ($event['event_id'] ?? '');
        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventId === '' || $eventType === '') {
            throw new \RuntimeException('Malformed Paddle event.');
        }

        DB::transaction(function () use ($event, $eventId, $eventType, $credits): void {
            if (DB::table('paddle_webhook_events')->where('event_id', $eventId)->exists()) {
                return;
            }

            if (in_array($eventType, ['subscription.created', 'subscription.updated', 'subscription.activated', 'subscription.canceled', 'subscription.paused', 'subscription.resumed'], true)) {
                $this->syncSubscription($event);
            }
            if ($eventType === 'transaction.completed') {
                $this->grantReelCredits($event, $credits);
            }

            DB::table('paddle_webhook_events')->insert([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'occurred_at' => $this->date($event['occurred_at'] ?? null),
                'processed_at' => now(),
            ]);
        }, 3);
    }

    private function grantReelCredits(array $event, ReelCreditService $credits): void
    {
        $data = $event['data'] ?? [];
        $transactionId = (string) ($data['id'] ?? '');
        $priceId = (string) config('paddle.reel_credit_price');
        $billingReference = data_get($data, 'custom_data.billing_reference');
        $purchaseKind = data_get($data, 'custom_data.purchase_kind');
        if ($transactionId === '' || $priceId === '' || $purchaseKind !== 'reel_credits' || ! is_string($billingReference) || $billingReference === '') {
            return;
        }
        $quantity = collect((array) ($data['items'] ?? []))->sum(function ($item) use ($priceId): int {
            $itemPriceId = (string) data_get($item, 'price.id', data_get($item, 'price_id', ''));

            return hash_equals($priceId, $itemPriceId) ? max(0, (int) data_get($item, 'quantity', 0)) : 0;
        });
        if ($quantity < (int) config('paddle.reel_minimum_purchase', 10)) {
            return;
        }
        $organizationId = filter_var(Crypt::decryptString($billingReference), FILTER_VALIDATE_INT);
        $organization = $organizationId ? Organization::query()->find($organizationId) : null;
        if (! $organization) {
            throw new \RuntimeException('Paddle Reel purchase has no valid organization mapping.');
        }
        $credits->grantPurchase($organization, $quantity, $transactionId, [
            'paddle_event_id' => $event['event_id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
        ]);
    }

    private function syncSubscription(array $event): void
    {
        $data = $event['data'] ?? [];
        $subscriptionId = (string) ($data['id'] ?? '');
        $organizationId = null;
        $billingReference = $data['custom_data']['billing_reference'] ?? null;
        if (is_string($billingReference) && $billingReference !== '') {
            $organizationId = filter_var(Crypt::decryptString($billingReference), FILTER_VALIDATE_INT);
        }
        $existing = $subscriptionId !== '' ? PaddleSubscription::where('paddle_subscription_id', $subscriptionId)->first() : null;
        $organizationId = $organizationId ?: $existing?->organization_id;

        if ($subscriptionId === '' || ! $organizationId || ! Organization::whereKey($organizationId)->exists()) {
            throw new \RuntimeException('Paddle subscription has no valid organization mapping.');
        }

        $occurredAt = $this->date($event['occurred_at'] ?? null);
        if ($existing?->paddle_occurred_at && $occurredAt && $existing->paddle_occurred_at->gt($occurredAt)) {
            return;
        }

        $item = $data['items'][0] ?? [];
        PaddleSubscription::updateOrCreate(
            ['paddle_subscription_id' => $subscriptionId],
            [
                'organization_id' => $organizationId,
                'environment' => config('paddle.environment'),
                'paddle_customer_id' => $data['customer_id'] ?? null,
                'paddle_price_id' => $item['price']['id'] ?? $item['price_id'] ?? null,
                'status' => $data['status'] ?? 'unknown',
                'trial_ends_at' => $this->date($data['current_billing_period']['ends_at'] ?? null, ($data['status'] ?? null) === 'trialing'),
                'current_period_ends_at' => $this->date($data['current_billing_period']['ends_at'] ?? null),
                'scheduled_change_action' => $data['scheduled_change']['action'] ?? null,
                'scheduled_change_at' => $this->date($data['scheduled_change']['effective_at'] ?? null),
                'paddle_occurred_at' => $occurredAt,
                'items' => $data['items'] ?? [],
            ]
        );
    }

    private function date(mixed $value, bool $condition = true): ?CarbonImmutable
    {
        return $condition && is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
