<?php

namespace App\Services;

class MetaWebhookNormalizer
{
    /**
     * Convert both Messenger and Instagram webhook shapes into a small,
     * channel-neutral event contract. The original webhook is intentionally
     * not persisted because it may contain unnecessary personal data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $payload): array
    {
        $provider = match ($payload['object'] ?? null) {
            'page' => 'facebook',
            'instagram' => 'instagram',
            default => null,
        };

        if ($provider === null) {
            return [];
        }

        $events = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $accountId = (string) ($entry['id'] ?? '');
            if ($accountId === '') {
                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $normalized = $this->messageEvent($provider, $accountId, $event);
                if ($normalized !== null) {
                    $events[] = $normalized;
                }

                foreach ((array) data_get($event, 'delivery.mids', []) as $messageId) {
                    if (is_string($messageId) && $messageId !== '') {
                        $events[] = [
                            'kind' => 'delivery',
                            'provider' => $provider,
                            'external_account_id' => $accountId,
                            'provider_message_id' => $messageId,
                            'sender_id' => (string) data_get($event, 'sender.id', ''),
                            'timestamp' => $this->timestamp($event['timestamp'] ?? null),
                        ];
                    }
                }

                if (isset($event['read'])) {
                    $events[] = [
                        'kind' => 'read',
                        'provider' => $provider,
                        'external_account_id' => $accountId,
                        'sender_id' => (string) data_get($event, 'sender.id', ''),
                        'watermark' => $this->timestamp(data_get($event, 'read.watermark')),
                        'timestamp' => $this->timestamp($event['timestamp'] ?? null),
                    ];
                }
            }
        }

        return $events;
    }

    private function messageEvent(string $provider, string $accountId, array $event): ?array
    {
        $message = $event['message'] ?? null;
        if (is_array($message) && ($message['is_echo'] ?? false)) {
            $messageId = (string) ($message['mid'] ?? '');

            $attachments = collect((array) ($message['attachments'] ?? []))
                ->map(fn ($attachment): array => ['type' => (string) ($attachment['type'] ?? 'file')])
                ->take(5)
                ->values()
                ->all();
            $text = isset($message['text']) ? trim((string) $message['text']) : null;
            $type = 'text';
            if (($text === null || $text === '') && $attachments !== []) {
                $type = 'attachment';
                $labels = collect($attachments)->pluck('type')->unique()->implode(', ');
                $text = "[Human operator sent an attachment: {$labels}]";
            }

            return $messageId === '' ? null : [
                'kind' => 'echo',
                'provider' => $provider,
                'external_account_id' => $accountId,
                'provider_message_id' => $messageId,
                'sender_id' => (string) data_get($event, 'sender.id', $accountId),
                'recipient_id' => (string) data_get($event, 'recipient.id', ''),
                'message_type' => $type,
                'text' => is_string($text) ? mb_substr($text, 0, 4000) : null,
                'attachments' => $attachments,
                'app_id' => isset($message['app_id']) ? (string) $message['app_id'] : null,
                'timestamp' => $this->timestamp($event['timestamp'] ?? null),
            ];
        }

        $text = null;
        $type = 'text';
        $attachments = [];
        $providerMessageId = null;
        $requiresHuman = false;

        if (is_array($message)) {
            $providerMessageId = isset($message['mid']) ? (string) $message['mid'] : null;
            $text = isset($message['text']) ? trim((string) $message['text']) : null;
            $attachments = collect((array) ($message['attachments'] ?? []))
                ->map(fn ($attachment): array => [
                    'type' => (string) ($attachment['type'] ?? 'file'),
                ])
                ->take(5)
                ->values()
                ->all();
            if (($text === null || $text === '') && $attachments !== []) {
                $type = 'attachment';
                $labels = collect($attachments)->pluck('type')->unique()->implode(', ');
                $text = "[Customer sent an attachment: {$labels}]";
                $requiresHuman = true;
            }
        } elseif (isset($event['postback'])) {
            $type = 'postback';
            $providerMessageId = data_get($event, 'postback.mid');
            $title = trim((string) data_get($event, 'postback.title', ''));
            $requiresHuman = $title === '';
            $text = $requiresHuman
                ? '[Customer used a Meta postback that requires human review.]'
                : $title;
        }

        $senderId = (string) data_get($event, 'sender.id', '');
        $recipientId = (string) data_get($event, 'recipient.id', $accountId);
        if ($text === null || $text === '' || $senderId === '') {
            return null;
        }

        $identityPayload = [
            'provider' => $provider,
            'account' => $accountId,
            'sender' => $senderId,
            'recipient' => $recipientId,
            'timestamp' => $event['timestamp'] ?? null,
            'text' => $text,
        ];
        $providerMessageId = is_string($providerMessageId) && $providerMessageId !== ''
            ? $providerMessageId
            : 'generated:'.hash('sha256', json_encode($identityPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'kind' => 'inbound',
            'provider' => $provider,
            'external_account_id' => $accountId,
            'provider_message_id' => $providerMessageId,
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'message_type' => $type,
            'text' => mb_substr($text, 0, 4000),
            'attachments' => $attachments,
            'requires_human' => $requiresHuman,
            'timestamp' => $this->timestamp($event['timestamp'] ?? null),
        ];
    }

    private function timestamp(mixed $milliseconds): ?string
    {
        if (! is_numeric($milliseconds)) {
            return null;
        }

        $value = (int) $milliseconds;
        if ($value <= 0) {
            return null;
        }
        if ($value > 9999999999) {
            $value = intdiv($value, 1000);
        }

        return now()->setTimestamp($value)->toIso8601String();
    }
}
