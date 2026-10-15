<?php

namespace App\Http\Controllers;

use App\Models\CommerceAttribution;
use App\Models\CommerceConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommerceWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();
        if (strlen($body) > 65_536) {
            return response()->json(['error' => 'Payload too large.'], 413);
        }

        $keyId = (string) $request->header('X-Legatus-Key');
        $timestamp = (string) $request->header('X-Legatus-Timestamp');
        $nonce = (string) $request->header('X-Legatus-Nonce');
        $signature = (string) $request->header('X-Legatus-Signature');
        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300
            || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)
            || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $canonical = implode("\n", ['POST', '/webhooks/commerce', $timestamp, $nonce, hash('sha256', $body)]);
        $connection = CommerceConnection::query()->where('status', 'active')->where('key_id', $keyId)->get()
            ->first(fn (CommerceConnection $candidate): bool => hash_equals(hash_hmac('sha256', $canonical, $candidate->secret), $signature));
        if (! $connection) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }
        if (! Cache::add('commerce-webhook-nonce:'.hash('sha256', $keyId.'|'.$nonce), true, now()->addMinutes(10))) {
            return response()->json(['error' => 'Duplicate request.'], 409);
        }

        $data = json_decode($body, true);
        $eventId = is_array($data) ? Str::limit(trim((string) ($data['event_id'] ?? '')), 191, '') : '';
        $type = is_array($data) ? (string) ($data['type'] ?? '') : '';
        $token = is_array($data) ? (string) ($data['attribution_token'] ?? '') : '';
        $amount = is_array($data) && is_numeric($data['amount'] ?? null) ? round((float) $data['amount'], 2) : -1;
        $currency = is_array($data) ? strtoupper(trim((string) ($data['currency'] ?? ''))) : '';
        if ($eventId === '' || ! in_array($type, ['order.completed', 'order.refunded'], true)
            || ! Str::isUuid($token) || $amount < 0 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            return response()->json(['error' => 'Invalid event.'], 422);
        }

        $found = DB::transaction(function () use ($connection, $data, $eventId, $type, $token, $amount, $currency): bool {
            $attribution = CommerceAttribution::query()
                ->where('agent_id', $connection->agent_id)
                ->where('commerce_connection_id', $connection->id)
                ->where('token', $token)
                ->lockForUpdate()
                ->first();
            if (! $attribution) {
                return false;
            }
            $inserted = DB::table('commerce_attribution_events')->insertOrIgnore([
                'commerce_attribution_id' => $attribution->id,
                'commerce_connection_id' => $connection->id,
                'provider_event_id' => $eventId,
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                return true;
            }

            $orderReference = trim((string) ($data['order_reference'] ?? ''));
            if ($type === 'order.completed') {
                if ($attribution->converted_at === null) {
                    $attribution->update([
                        'status' => 'converted', 'provider_event_id' => $eventId,
                        'order_reference_hash' => $orderReference === '' ? null : hash_hmac('sha256', $orderReference, (string) config('app.key')),
                        'gross_amount' => $amount, 'currency' => $currency, 'converted_at' => now(),
                    ]);
                }
            } else {
                if ($attribution->converted_at === null || $attribution->currency !== $currency) {
                    return true;
                }
                $refunded = min(
                    (float) $attribution->gross_amount,
                    (float) $attribution->refunded_amount + $amount,
                );
                $attribution->update([
                    'status' => 'refunded', 'provider_event_id' => $eventId, 'refunded_amount' => $refunded,
                    'currency' => $attribution->currency ?: $currency, 'refunded_at' => now(),
                ]);
            }
            $net = max(0, (float) ($attribution->fresh()->gross_amount ?? 0) - (float) ($attribution->fresh()->refunded_amount ?? 0));
            $attribution->conversation()->update(['outcome' => $net > 0 ? 'verified_purchase' : 'verified_refund', 'outcome_value' => $net]);

            return true;
        });

        if (! $found) {
            return response()->json(['error' => 'Unknown attribution.'], 404);
        }

        return response()->json(['ok' => true]);
    }
}
