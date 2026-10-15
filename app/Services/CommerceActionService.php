<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\CommerceActionRequest;
use App\Models\CommerceConnection;
use App\Models\CommerceAttribution;
use App\Models\Conversation;
use App\Support\PrivacyRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CommerceActionService
{
    private const CAPABILITIES = [
        'order_status',
        'cart_create',
        'order_cancel',
        'return_create',
        'shipping_address_update',
    ];

    private const ACTION_CAPABILITIES = [
        'cancel_order' => 'order_cancel',
        'start_return' => 'return_create',
        'update_shipping_address' => 'shipping_address_update',
    ];

    public function __construct(private CommerceConnectorClient $connector) {}

    /** @return list<string> */
    public function capabilities(Agent $agent): array
    {
        $connection = $agent->commerceConnection()->where('status', 'active')->first();
        if (! $connection) {
            return [];
        }

        return collect(data_get($connection->settings, 'capabilities', []))
            ->filter(fn ($capability): bool => is_string($capability) && in_array($capability, self::CAPABILITIES, true))
            ->unique()
            ->values()
            ->all();
    }

    public function orderStatus(Agent $agent, Conversation $conversation, array $arguments): array
    {
        $connection = $this->connectionFor($agent, 'order_status');
        if (! $connection) {
            return $this->unsupported('order status lookup');
        }

        $orderReference = $this->requiredText($arguments['order_reference'] ?? null, 100);
        $email = filter_var($arguments['customer_email'] ?? null, FILTER_VALIDATE_EMAIL)
            ? Str::lower(trim((string) $arguments['customer_email']))
            : null;
        $phone = $this->normalizedPhone($arguments['customer_phone'] ?? null);
        if ($orderReference === null || (! $email && ! $phone)) {
            return ['ok' => false, 'error' => 'The order reference and the customer email or phone are required.'];
        }
        if (! $this->latestCustomerMessageContainsContact($conversation, $email, $phone)) {
            return ['ok' => false, 'error' => 'For privacy, the matching email or phone must be supplied by the customer in this same message.'];
        }

        try {
            $response = $this->connector->orderStatus($connection, array_filter([
                'order_reference' => $orderReference,
                'customer_email' => $email,
                'customer_phone' => $phone,
            ]));
            $data = data_get($response, 'data');
            if (! is_array($data)) {
                throw new \RuntimeException('The commerce connector returned an invalid order status.');
            }

            $status = $this->requiredText($data['status'] ?? null, 80);
            if ($status === null) {
                throw new \RuntimeException('The commerce connector omitted the order status.');
            }

            return [
                'ok' => true,
                'order_reference' => $orderReference,
                'status' => $status,
                'fulfillment_status' => $this->optionalText($data['fulfillment_status'] ?? null, 80),
                'carrier' => $this->optionalText($data['carrier'] ?? null, 120),
                'tracking_url' => $this->safeHttpsUrl($data['tracking_url'] ?? null),
                'estimated_delivery' => $this->optionalText($data['estimated_delivery'] ?? null, 120),
                'checked_at' => $this->optionalText($data['checked_at'] ?? null, 80) ?? now()->toIso8601String(),
                'source' => ['label' => $connection->name ?: 'Live commerce order', 'type' => 'live_order'],
            ];
        } catch (Throwable $exception) {
            $this->logFailure('order_status', $agent, $exception);

            return ['ok' => false, 'error' => 'The live order status could not be verified. No order details were changed.'];
        }
    }

    public function createCheckout(Agent $agent, Conversation $conversation, array $arguments): array
    {
        $connection = $this->connectionFor($agent, 'cart_create');
        if (! $connection) {
            return $this->unsupported('checkout cart creation');
        }

        $requested = collect($arguments['items'] ?? [])
            ->filter(fn ($item): bool => is_array($item))
            ->groupBy(fn (array $item): int => (int) ($item['product_id'] ?? 0))
            ->map(fn ($items, $productId): array => [
                'product_id' => (int) $productId,
                'quantity' => (int) $items->sum(fn (array $item): int => (int) ($item['quantity'] ?? 0)),
            ])
            ->values();
        if ($requested->isEmpty() || $requested->count() > 20) {
            return ['ok' => false, 'error' => 'A checkout requires between 1 and 20 product lines.'];
        }

        $lines = [];
        $verifiedItems = [];
        foreach ($requested as $item) {
            if ($item['product_id'] <= 0 || $item['quantity'] <= 0 || $item['quantity'] > 100) {
                return ['ok' => false, 'error' => 'Each checkout line requires a valid product and quantity.'];
            }
            $product = $agent->customerProducts()
                ->where('is_active', true)
                ->where('commerce_connection_id', $connection->id)
                ->find($item['product_id']);
            if (! $product || blank($product->external_product_id)) {
                return ['ok' => false, 'error' => 'A selected product is not available through this connected store.'];
            }

            try {
                $availability = data_get($this->connector->availability($connection, $product->external_product_id), 'data');
            } catch (Throwable $exception) {
                $this->logFailure('checkout_availability', $agent, $exception);

                return ['ok' => false, 'error' => 'Live availability could not be verified, so no checkout cart was created.'];
            }
            if (! is_array($availability)
                || ($availability['purchasable'] ?? false) !== true
                || ($availability['in_stock'] ?? false) !== true
                || (isset($availability['quantity']) && (int) $availability['quantity'] < $item['quantity'])) {
                return ['ok' => false, 'error' => 'A selected quantity is not currently purchasable.', 'product_id' => $product->id];
            }
            $lines[] = ['product_id' => (string) $product->external_product_id, 'quantity' => $item['quantity']];
            $verifiedItems[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $item['quantity'],
            ];
        }

        $idempotencyKey = (string) Str::uuid();
        $attribution = CommerceAttribution::create([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'commerce_connection_id' => $connection->id,
            'status' => 'initiated',
        ]);
        try {
            $response = $this->connector->createCart($connection, $lines, $idempotencyKey, $attribution->token);
            $checkoutUrl = $this->safeStoreUrl($connection, data_get($response, 'data.checkout_url'));
            if ($checkoutUrl === null) {
                throw new \RuntimeException('The commerce connector returned an invalid checkout URL.');
            }

            $conversation->update(['outcome' => 'checkout_created']);

            return [
                'ok' => true,
                'checkout_url' => $checkoutUrl,
                'item_count' => count($lines),
                'items' => $verifiedItems,
                'requires_payment_confirmation' => true,
                'message' => 'The cart is ready. The customer must review the store checkout and confirm payment there.',
                'source' => ['label' => $connection->name ?: 'Live commerce checkout', 'type' => 'live_checkout'],
            ];
        } catch (Throwable $exception) {
            $attribution->delete();
            $this->logFailure('create_checkout', $agent, $exception);

            return ['ok' => false, 'error' => 'The checkout cart could not be created safely. No order or payment was submitted.'];
        }
    }

    public function prepare(Agent $agent, Conversation $conversation, array $arguments): array
    {
        $action = (string) ($arguments['action'] ?? '');
        $capability = self::ACTION_CAPABILITIES[$action] ?? null;
        $connection = $capability ? $this->connectionFor($agent, $capability) : null;
        if (! $connection) {
            return $this->unsupported('the requested order change');
        }

        $orderReference = $this->requiredText($arguments['order_reference'] ?? null, 100);
        if ($orderReference === null) {
            return ['ok' => false, 'error' => 'A valid order reference is required.'];
        }
        $input = $this->actionInput($action, (array) ($arguments['details'] ?? []));
        if ($input === null) {
            return ['ok' => false, 'error' => 'The requested order change is missing required details.'];
        }

        $message = $conversation->messages()->where('role', 'customer')->latest('id')->first();
        if (! $message) {
            return ['ok' => false, 'error' => 'The action must originate from a customer message.'];
        }

        $request = new CommerceActionRequest([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'commerce_connection_id' => $connection->id,
            'requested_message_id' => $message->id,
            'action_type' => $action,
            'status' => 'pending_confirmation',
            'summary' => ['action' => $action, 'order_reference' => $orderReference],
            'payload' => ['input' => $input],
            'expires_at' => now()->addMinutes(15),
        ]);
        $request->save();

        try {
            $response = $this->connector->previewAction(
                $connection,
                $action,
                $orderReference,
                $input,
                $request->idempotency_key,
            );
            $data = data_get($response, 'data');
            $token = is_array($data) ? $this->requiredText($data['action_token'] ?? null, 1000) : null;
            $summary = is_array($data) ? $this->requiredText($data['summary'] ?? null, 500) : null;
            if ($token === null || $summary === null) {
                throw new \RuntimeException('The commerce connector returned an invalid action preview.');
            }
            $expiresAt = $this->boundedExpiry($data['expires_at'] ?? null);
            $request->update([
                'summary' => ['action' => $action, 'order_reference' => $orderReference, 'description' => PrivacyRedactor::text($summary)],
                'payload' => ['input' => $input, 'action_token' => $token],
                'expires_at' => $expiresAt,
            ]);

            return [
                'ok' => true,
                'action_request_id' => $request->public_id,
                'action' => $action,
                'summary' => PrivacyRedactor::text($summary),
                'expires_at' => $expiresAt->toIso8601String(),
                'requires_customer_confirmation' => true,
                'message' => 'Explain this exact preview and ask the customer to confirm. Do not execute it in the same turn.',
            ];
        } catch (Throwable $exception) {
            $request->update(['status' => 'failed', 'failed_at' => now(), 'last_error' => 'preview_failed']);
            $this->logFailure('preview_'.$action, $agent, $exception);

            return ['ok' => false, 'error' => 'The requested change could not be previewed safely. Nothing was changed.'];
        }
    }

    public function confirm(Agent $agent, Conversation $conversation, array $arguments): array
    {
        $publicId = $this->requiredText($arguments['action_request_id'] ?? null, 60);
        $confirmationText = $this->requiredText($arguments['customer_confirmation'] ?? null, 300);
        $request = $publicId ? CommerceActionRequest::query()
            ->where('agent_id', $agent->id)
            ->where('conversation_id', $conversation->id)
            ->where('public_id', $publicId)
            ->first() : null;
        if (! $request || $request->status !== 'pending_confirmation') {
            return ['ok' => false, 'error' => 'No matching pending commerce action was found.'];
        }
        if ($request->expires_at->isPast()) {
            $request->update(['status' => 'expired']);

            return ['ok' => false, 'error' => 'This action preview expired. Prepare a fresh preview before asking again.'];
        }

        $latest = $conversation->messages()->where('role', 'customer')->latest('id')->first();
        if (! $latest
            || $latest->id <= (int) $request->requested_message_id
            || $confirmationText === null
            || Str::lower(Str::squish($confirmationText)) !== Str::lower(Str::squish((string) $latest->content))) {
            return ['ok' => false, 'error' => 'A separate, explicit customer confirmation message is required before this action can run.'];
        }

        $connection = CommerceConnection::query()
            ->whereKey($request->commerce_connection_id)
            ->where('agent_id', $agent->id)
            ->where('status', 'active')
            ->first();
        $token = data_get($request->payload, 'action_token');
        if (! $connection || ! is_string($token) || trim($token) === '') {
            return ['ok' => false, 'error' => 'The verified store action is no longer available. Nothing was changed.'];
        }

        $claimed = CommerceActionRequest::query()
            ->whereKey($request->id)
            ->where('status', 'pending_confirmation')
            ->update(['status' => 'processing', 'confirmed_at' => now()]);
        if ($claimed !== 1) {
            return ['ok' => false, 'error' => 'This action is already being processed.'];
        }

        try {
            $response = $this->connector->executeAction($connection, $token, $request->idempotency_key);
            $data = data_get($response, 'data');
            if (! is_array($data) || ($data['completed'] ?? false) !== true) {
                throw new \RuntimeException('The commerce connector did not confirm action completion.');
            }
            $reference = $this->optionalText($data['reference'] ?? null, 191);
            $request->update([
                'status' => 'completed',
                'executed_at' => now(),
                'connector_reference' => $reference,
                'last_error' => null,
            ]);
            $conversation->update(['outcome' => 'commerce_action_completed']);

            return [
                'ok' => true,
                'action_request_id' => $request->public_id,
                'action' => $request->action_type,
                'status' => 'completed',
                'summary' => data_get($request->summary, 'description'),
                'reference' => $reference,
                'source' => ['label' => $connection->name ?: 'Live commerce action', 'type' => 'live_action'],
            ];
        } catch (Throwable $exception) {
            // The remote store may have completed the request even when its
            // response was lost. Keep this terminal until an operator checks
            // the store; automatically retrying a consequential action could
            // cancel, return, or update the same order twice.
            $request->update(['status' => 'delivery_unknown', 'failed_at' => now(), 'last_error' => 'execution_outcome_unknown']);
            $this->logFailure('execute_'.$request->action_type, $agent, $exception);

            return ['ok' => false, 'error' => 'The store did not confirm the change. Its outcome is unknown, so Legatus will not retry automatically.'];
        }
    }

    private function connectionFor(Agent $agent, string $capability): ?CommerceConnection
    {
        $connection = $agent->commerceConnection()->where('status', 'active')->first();

        return $connection && in_array($capability, $this->capabilities($agent), true) ? $connection : null;
    }

    private function unsupported(string $feature): array
    {
        return ['ok' => false, 'error' => "This connected store has not verified support for {$feature}. Do not promise or simulate it."];
    }

    private function latestCustomerMessageContainsContact(Conversation $conversation, ?string $email, ?string $phone): bool
    {
        $message = $conversation->messages()->where('role', 'customer')->latest('id')->first();
        if (! $message) {
            return false;
        }
        $evidence = array_merge_recursive(
            PrivacyRedactor::contactEvidence((string) $message->content),
            is_array(data_get($message->metadata, 'contact_evidence')) ? data_get($message->metadata, 'contact_evidence') : [],
        );

        return PrivacyRedactor::contactEvidenceMatches($evidence, $email, $phone);
    }

    private function actionInput(string $action, array $details): ?array
    {
        if ($action === 'cancel_order') {
            $reason = $this->requiredText($details['reason'] ?? null, 500);

            return $reason ? ['reason' => $reason] : null;
        }
        if ($action === 'start_return') {
            $reason = $this->requiredText($details['reason'] ?? null, 500);
            $itemIds = collect($details['item_ids'] ?? [])->filter(fn ($id): bool => is_string($id) || is_int($id))
                ->map(fn ($id): string => Str::limit(trim((string) $id), 120, ''))->filter()->unique()->take(50)->values()->all();

            return $reason && $itemIds !== [] ? ['reason' => $reason, 'item_ids' => $itemIds] : null;
        }
        if ($action === 'update_shipping_address') {
            $address = [];
            foreach (['recipient', 'line1', 'line2', 'city', 'region', 'postal_code', 'country'] as $field) {
                $address[$field] = $this->optionalText($details[$field] ?? null, $field === 'line1' || $field === 'line2' ? 200 : 120);
            }
            if (! $address['recipient'] || ! $address['line1'] || ! $address['city'] || ! $address['country']) {
                return null;
            }

            return ['address' => array_filter($address, fn ($value): bool => $value !== null)];
        }

        return null;
    }

    private function boundedExpiry(mixed $value): CarbonImmutable
    {
        try {
            $candidate = CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return CarbonImmutable::now()->addMinutes(15);
        }

        if ($candidate->isPast()) {
            return CarbonImmutable::now()->addMinute();
        }

        return $candidate->min(CarbonImmutable::now()->addMinutes(15));
    }

    private function safeStoreUrl(CommerceConnection $connection, mixed $value): ?string
    {
        $url = $this->safeHttpsUrl($value);
        if (! $url) {
            return null;
        }

        return Str::lower((string) parse_url($url, PHP_URL_HOST)) === Str::lower((string) parse_url($connection->base_url, PHP_URL_HOST))
            ? $url
            : null;
    }

    private function safeHttpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);

        return ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) ? $value : null;
    }

    private function requiredText(mixed $value, int $limit): ?string
    {
        $value = $this->optionalText($value, $limit);

        return $value !== null && $value !== '' ? $value : null;
    }

    private function optionalText(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = Str::squish(strip_tags((string) $value));

        return $text === '' ? null : Str::limit($text, $limit, '');
    }

    private function normalizedPhone(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : null;
    }

    private function logFailure(string $action, Agent $agent, Throwable $exception): void
    {
        Log::warning('Tenant commerce action failed.', [
            'action' => $action,
            'agent_id' => $agent->id,
            'exception_type' => $exception::class,
        ]);
    }
}
