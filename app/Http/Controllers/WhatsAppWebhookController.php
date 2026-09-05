<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaInboundMessage;
use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('whatsapp.verify_token');
        abort_if($token === '', 503, 'WhatsApp webhook verification is not configured.');

        $mode = (string) ($request->query('hub.mode') ?? $request->query('hub_mode', ''));
        $providedToken = (string) ($request->query('hub.verify_token') ?? $request->query('hub_verify_token', ''));
        $challenge = (string) ($request->query('hub.challenge') ?? $request->query('hub_challenge', ''));
        abort_unless(
            $mode === 'subscribe'
            && $challenge !== ''
            && hash_equals($token, $providedToken),
            403,
        );

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $body = $request->getContent();
        abort_if(strlen($body) > max(1024, (int) config('whatsapp.max_webhook_bytes')), 413);
        abort_unless($this->validSignature($body, (string) $request->header('X-Hub-Signature-256')), 401);

        try {
            $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400, 'Invalid webhook payload.');
        }
        abort_unless(is_array($payload) && ($payload['object'] ?? null) === 'whatsapp_business_account', 400);

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                $value = $change['value'];
                $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id', '');
                $connection = ChannelConnection::query()->where('provider', 'whatsapp')
                    ->where('external_account_id', $phoneNumberId)->first();
                if (! $connection) {
                    continue;
                }
                $connection->update(['last_webhook_at' => now()]);
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $this->acceptInbound($connection, $message, $value);
                }
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->acceptStatus($connection, $status);
                }
            }
        }

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }

    private function acceptInbound(ChannelConnection $connection, array $message, array $value): void
    {
        $id = trim((string) ($message['id'] ?? ''));
        $sender = trim((string) ($message['from'] ?? ''));
        if ($id === '' || $sender === '') {
            return;
        }
        $type = (string) ($message['type'] ?? 'unknown');
        $text = $type === 'text' ? trim((string) data_get($message, 'text.body', '')) : '';
        $requiresHuman = $type !== 'text' || $text === '';
        if ($requiresHuman) {
            $text = '[Customer sent a WhatsApp '.preg_replace('/[^a-z0-9_-]/i', '', $type).' message that requires human review.]';
        }

        try {
            $record = ChannelMessage::query()->firstOrCreate([
                'channel_connection_id' => $connection->id,
                'direction' => 'inbound',
                'provider_message_id' => $id,
            ], [
                'provider_sender_id' => $sender,
                'provider_recipient_id' => $connection->external_account_id,
                'message_type' => $requiresHuman ? 'attachment' : 'text',
                'status' => 'received',
                'payload' => [
                    'text' => mb_substr($text, 0, 4096),
                    'attachments' => $requiresHuman ? [['type' => $type]] : [],
                    'requires_human' => $requiresHuman,
                    'provider_timestamp' => $this->date($message['timestamp'] ?? null)?->toIso8601String(),
                    'customer_name' => data_get($value, 'contacts.0.profile.name'),
                ],
                'received_at' => $this->date($message['timestamp'] ?? null) ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }
        if ($record->wasRecentlyCreated) {
            ProcessMetaInboundMessage::dispatch($record->id)->onQueue('channels')->afterCommit();
        }
    }

    private function acceptStatus(ChannelConnection $connection, array $status): void
    {
        $providerId = trim((string) ($status['id'] ?? ''));
        $state = (string) ($status['status'] ?? '');
        if ($providerId === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $record = ChannelMessage::query()->where('channel_connection_id', $connection->id)
            ->where('direction', 'outbound')->where('provider_message_id', $providerId)->first();
        if (! $record) {
            return;
        }
        $rank = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];
        if ($state === 'failed' && in_array($record->status, ['delivered', 'read'], true)) {
            return;
        }
        if ($state !== 'failed' && ($rank[$state] ?? 0) < ($rank[$record->status] ?? 0)) {
            return;
        }

        $updates = ['status' => $state];
        if ($state === 'sent') {
            $updates['sent_at'] = $this->date($status['timestamp'] ?? null) ?? now();
        }
        if (in_array($state, ['delivered', 'read'], true)) {
            $updates['delivered_at'] = $this->date($status['timestamp'] ?? null) ?? now();
        }
        if ($state === 'failed') {
            $updates['failed_at'] = now();
            $updates['failure_reason'] = 'WhatsApp rejected message delivery.';
        }
        $record->update($updates);
    }

    private function validSignature(string $body, string $provided): bool
    {
        $secret = (string) config('whatsapp.app_secret');

        return $secret !== '' && str_starts_with($provided, 'sha256=')
            && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $provided);
    }

    private function date(mixed $timestamp): ?Carbon
    {
        if (! is_numeric($timestamp) || (int) $timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $timestamp);
    }
}
