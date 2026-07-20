<?php

namespace App\Services;

use App\Jobs\SendMetaMessage;
use App\Models\ChannelMessage;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ChannelMessageDispatcher
{
    public function dispatch(Message $message): ?ChannelMessage
    {
        if (! in_array($message->role, ['assistant', 'human'], true)) {
            return null;
        }

        $conversation = $message->conversation()->with('channelConnection')->first();
        if (! $conversation instanceof Conversation || ! in_array($conversation->channel, ['facebook', 'instagram'], true)) {
            return null;
        }

        $connection = $conversation->channelConnection;
        if ($connection === null || ! $connection->isActive() || ! is_string($conversation->external_thread_id) || $conversation->external_thread_id === '') {
            return null;
        }

        $text = $this->actionableText($message, $connection->provider);

        $delivery = ChannelMessage::query()->firstOrCreate(
            ['message_id' => $message->id],
            [
                'channel_connection_id' => $connection->id,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'provider_sender_id' => $connection->external_account_id,
                'provider_recipient_id' => $conversation->external_thread_id,
                'message_type' => 'text',
                'status' => 'queued',
                'payload' => ['text' => $text, 'role' => $message->role],
                'queued_at' => now(),
            ],
        );

        if (in_array($delivery->status, ['queued', 'retrying'], true)) {
            try {
                SendMetaMessage::dispatch($delivery->id)->afterCommit();
            } catch (\Throwable) {
                // The durable outbox row is authoritative; the scheduled
                // sweeper will retry inserting the queue job.
            }
        }

        return $delivery;
    }

    private function actionableText(Message $message, string $provider): string
    {
        if ($message->role !== 'assistant') {
            return $this->providerText($message->content, collect(), $provider);
        }

        $links = collect(data_get($message->metadata, 'products', []))
            ->map(function ($product): ?string {
                $url = data_get($product, 'url');
                if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    return null;
                }
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if (! in_array($scheme, ['http', 'https'], true)) {
                    return null;
                }

                $name = trim((string) data_get($product, 'name'));

                return $name !== '' ? Str::limit($name, 100, '').': '.$url : $url;
            })
            ->filter()
            ->unique()
            ->take(3)
            ->values();

        return $this->providerText($message->content, $links, $provider);
    }

    /**
     * Keep product links atomic when applying Meta's channel-specific limits.
     * A partial URL looks actionable to a customer but cannot be opened.
     */
    private function providerText(string $content, Collection $links, string $provider): string
    {
        // Instagram's text field must be fewer than 1000 Unicode characters;
        // Messenger allows 2000. Both branches preserve whole product links.
        $maxCharacters = $provider === 'instagram' ? 999 : 2000;
        $content = trim($content);
        $selectedLinks = [];

        foreach ($links as $line) {
            $candidate = [...$selectedLinks, $line];
            $linkCharacters = mb_strlen(implode("\n", $candidate), 'UTF-8');
            // Leave room for a separator and at least one character of
            // prose when the original response is not empty.
            $minimumProseCharacters = $content === '' ? 0 : 4;
            if ($linkCharacters + ($content === '' ? 0 : 2) + $minimumProseCharacters <= $maxCharacters) {
                $selectedLinks = $candidate;
            }
        }

        $linkBlock = implode("\n", $selectedLinks);
        $separator = $content !== '' && $linkBlock !== '' ? "\n\n" : '';
        $proseBudget = $maxCharacters
            - mb_strlen($linkBlock, 'UTF-8')
            - mb_strlen($separator, 'UTF-8');
        $prose = $this->truncateUtf8Characters($content, $proseBudget);

        return $prose.$separator.$linkBlock;
    }

    private function truncateUtf8Characters(string $text, int $maxCharacters): string
    {
        if ($maxCharacters <= 0 || $text === '') {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') <= $maxCharacters) {
            return $text;
        }

        $suffix = '...';
        if ($maxCharacters <= mb_strlen($suffix, 'UTF-8')) {
            return mb_substr($text, 0, $maxCharacters, 'UTF-8');
        }

        return rtrim(mb_substr($text, 0, $maxCharacters - mb_strlen($suffix, 'UTF-8'), 'UTF-8')).$suffix;
    }
}
