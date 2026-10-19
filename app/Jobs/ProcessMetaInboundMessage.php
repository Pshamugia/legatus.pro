<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiReelDelivery;
use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SocialMediaPost;
use App\Services\ChannelMessageDispatcher;
use App\Services\ConversationEngine;
use App\Services\CustomerImageCatalogMatcher;
use App\Services\MetaGraphClient;
use App\Services\PublicCommentGate;
use App\Support\PrivacyRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class ProcessMetaInboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const META_SPLIT_MEDIA_WINDOW_SECONDS = 15;

    public const TIMEOUT_SECONDS = 150;

    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $uniqueFor = 600;

    public function __construct(public int $channelMessageId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->channelMessageId;
    }

    public function handle(
        ConversationEngine $engine,
        ChannelMessageDispatcher $dispatcher,
        ?CustomerImageCatalogMatcher $imageMatcher = null,
        ?PublicCommentGate $commentGate = null,
        ?MetaGraphClient $meta = null,
    ): void {
        $record = ChannelMessage::query()->with('connection.agent')->find($this->channelMessageId);
        if (! $record || in_array($record->status, ['processed', 'ignored'], true)) {
            return;
        }

        $connection = $record->connection;
        if (! $connection || ! $connection->isActive()) {
            $reason = 'The Meta channel connection is unavailable.';
            $preserved = $this->preserveWithoutPausingAi($record, $reason);
            $updates = [
                'status' => 'failed',
                'failure_reason' => $reason,
                'failed_at' => now(),
            ];
            if ($preserved) {
                $updates['payload'] = $this->minimalPayload($record);
            }
            $record->update($updates);

            return;
        }

        $senderId = (string) $record->provider_sender_id;
        $text = trim((string) data_get($record->payload, 'text', ''));
        if ($record->message_type === 'comment' && $text === '') {
            $details = ($meta ?? app(MetaGraphClient::class))->commentDetails(
                $connection,
                (string) $record->provider_message_id,
            );
            $text = trim((string) ($connection->provider === 'instagram'
                ? ($details['text'] ?? '')
                : ($details['message'] ?? '')));
            if ($text !== '') {
                $text = PrivacyRedactor::text($text);
                $payload = is_array($record->payload) ? $record->payload : [];
                $payload['text'] = $text;
                $payload['comment_text_hydrated'] = true;
                $record->update(['payload' => $payload]);
            }
        }
        if ($senderId === '' || $text === '') {
            $record->update([
                'status' => 'ignored',
                'payload' => $this->minimalPayload($record),
                'processed_at' => now(),
            ]);

            return;
        }

        if ((bool) data_get($record->payload, 'suppress_reply', false)) {
            $reason = 'Passive Meta story mention does not require an automated reply.';
            $preserved = $this->preserveWithoutPausingAi(
                $record,
                $reason,
                transportFailure: false,
                automaticProcessingFailure: false,
            );
            $record->update($preserved
                ? ['status' => 'processed', 'payload' => $this->minimalPayload($record), 'processed_at' => now()]
                : ['status' => 'failed', 'failure_reason' => $reason, 'failed_at' => now()]);

            return;
        }

        if ((bool) data_get($record->payload, 'requires_human', false)) {
            if ($record->message_type === 'attachment') {
                $this->replyThatAttachmentCannotBeRead($record, $connection, $senderId, $text, $dispatcher);

                return;
            }

            $reason = 'The customer sent media or an ungrounded postback that requires human review.';
            $preserved = $this->preserveWithoutPausingAi($record, $reason, transportFailure: false);
            $updates = $preserved
                ? ['status' => 'processed', 'payload' => $this->minimalPayload($record), 'processed_at' => now()]
                : ['status' => 'failed', 'failure_reason' => $reason, 'failed_at' => now()];
            $record->update($updates);

            return;
        }

        Cache::lock('meta-inbound:'.$connection->id.':'.hash('sha256', $senderId), 120)
            ->block(20, function () use ($record, $connection, $senderId, $text, $engine, $dispatcher, $imageMatcher, $commentGate): void {
                $record->refresh();
                if (in_array($record->status, ['processed', 'ignored'], true)) {
                    return;
                }

                // Meta may deliver adjacent messages in separate webhooks and
                // multiple queue workers can acquire those jobs out of order.
                // Do not let a later turn become the conversation context
                // before an older message from the same customer is handled.
                if ($this->hasEarlierPendingInbound($record)) {
                    $this->release(2);

                    return;
                }

                $record->update([
                    'status' => 'processing',
                    'attempts' => $record->attempts + 1,
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);

                $isComment = $record->message_type === 'comment';
                $customerInput = $text;
                $postId = trim((string) data_get($record->payload, 'post_id', ''));
                $customerId = $isComment
                    ? "meta-comment:{$connection->provider}:{$connection->id}:".hash('sha256', $senderId.':'.$postId)
                    : "meta:{$connection->provider}:{$connection->id}:{$senderId}";
                $conversationChannel = $isComment ? $connection->provider.'_comment' : $connection->provider;

                if ($isComment) {
                    $this->prepareCommentConversation($record, $connection, $customerId, $postId);
                    $conversation = $connection->agent->conversations()
                        ->where('visitor_id', $customerId)
                        ->where('channel', $conversationChannel)
                        ->latest('id')
                        ->firstOrFail();
                    $decision = ($commentGate ?? app(PublicCommentGate::class))->decide($text, $conversation);
                    if ($decision['action'] !== 'reply') {
                        $this->finishCommentWithoutAutomaticReply($record, $conversation, $text, $decision);

                        return;
                    }
                }

                $imageRecord = $this->customerImageRecord($record);
                if (! $isComment && $imageRecord) {
                    $customerRecord = $record;
                    $imageQuestion = $text;
                    if ($imageRecord->is($record) && ($pairedText = $this->customerTextForImage($record))) {
                        $customerRecord = $pairedText;
                        $imageQuestion = trim((string) data_get($pairedText->payload, 'text', $text));
                    }
                    $this->replyFromCustomerImage(
                        $customerRecord,
                        $imageRecord,
                        $connection,
                        $senderId,
                        $imageQuestion,
                        $imageMatcher ?? app(CustomerImageCatalogMatcher::class),
                        $dispatcher,
                    );

                    return;
                }
                if (! $isComment && $record->message_type === 'attachment') {
                    $this->replyThatAttachmentCannotBeRead($record, $connection, $senderId, $text, $dispatcher);

                    return;
                }

                $result = $engine->handle(
                    $connection->agent,
                    $customerInput,
                    $conversationChannel,
                    $customerId,
                    $record->idempotency_key,
                    ucfirst($connection->provider).' customer',
                );

                $conversation = Conversation::query()->findOrFail((int) $result['conversation_id']);
                $conversation->update([
                    'channel_connection_id' => $connection->id,
                    'external_thread_id' => $isComment ? (string) $record->provider_message_id : $senderId,
                ]);

                $customerMessage = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('public_id', $result['customer_message_id'] ?? null)
                    ->first();
                $record->update([
                    'conversation_id' => $conversation->id,
                    'message_id' => $customerMessage?->id,
                ]);

                if (is_string($result['message_id'] ?? null)) {
                    $assistant = Message::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('public_id', $result['message_id'])
                        ->first();
                    if ($assistant) {
                        $conversation->refresh();
                        if ($conversation->status === 'ai') {
                            if (! $isComment) {
                                $this->discloseAiIdentityOnFirstReply($assistant, $conversation, $connection->agent, $text);
                            }
                            $dispatcher->dispatch($assistant);
                        } else {
                            $assistant->delete();
                        }
                    }
                }

                $record->update([
                    'status' => 'processed',
                    'payload' => $this->minimalPayload($record),
                    'processed_at' => now(),
                ]);
            });
    }

    private function prepareCommentConversation(
        ChannelMessage $record,
        ChannelConnection $connection,
        string $customerId,
        string $postId,
    ): void {
        $conversation = $connection->agent->conversations()
            ->where('visitor_id', $customerId)
            ->where('channel', $connection->provider.'_comment')
            ->whereIn('status', ['ai', 'open', 'human'])
            ->latest('id')
            ->first() ?? $connection->agent->conversations()->create([
                'visitor_id' => $customerId,
                'customer_name' => ucfirst($connection->provider).' commenter',
                'channel' => $connection->provider.'_comment',
                'status' => 'ai',
            ]);

        $context = is_array($conversation->context) ? $conversation->context : [];
        $context['source_type'] = 'social_comment';
        $context['provider'] = $connection->provider;
        $context['post_id'] = $postId;
        $context['reply_mode'] = 'public_comment';

        $post = SocialMediaPost::query()
            ->where('agent_id', $connection->agent_id)
            ->where('provider', $connection->provider)
            ->where('provider_post_id', $postId)
            ->whereNotNull('product_id')
            ->latest('id')
            ->first();
        $reelDelivery = $post ? null : AiReelDelivery::query()
            ->where('provider', $connection->provider)
            ->where('provider_post_id', $postId)
            ->where('status', 'published')
            ->whereHas('reel', fn ($query) => $query->where('agent_id', $connection->agent_id))
            ->with('reel')
            ->latest('id')
            ->first();
        $linkedProductId = $post?->product_id ?? $reelDelivery?->reel?->product_id;
        if ($linkedProductId && $connection->agent->customerProducts()->whereKey($linkedProductId)->exists()) {
            $context['last_catalog_product_ids'] = [(int) $linkedProductId];
            $context['comment_product_id'] = (int) $linkedProductId;
        } else {
            unset($context['last_catalog_product_ids'], $context['comment_product_id']);
        }
        $caption = $reelDelivery?->reel?->caption;
        if (is_string($caption) && trim($caption) !== '') {
            $context['comment_post_caption'] = Str::limit(PrivacyRedactor::text($caption), 1200, '');
        } else {
            unset($context['comment_post_caption']);
        }

        $conversation->update([
            'channel_connection_id' => $connection->id,
            'external_thread_id' => (string) $record->provider_message_id,
            'context' => $context,
            'last_message_at' => $record->received_at ?? now(),
        ]);
    }

    /** @param array{action: 'silent'|'review', reason: string} $decision */
    private function finishCommentWithoutAutomaticReply(
        ChannelMessage $record,
        Conversation $conversation,
        string $text,
        array $decision,
    ): void {
        $safeText = PrivacyRedactor::text($text);
        $customer = $conversation->messages()->firstOrCreate(
            ['request_id' => $record->idempotency_key],
            ['role' => 'customer', 'content' => $safeText, 'metadata' => [
                'public_comment_gate' => $decision['action'],
                'pii_redacted' => $safeText !== $text,
            ]],
        );
        $review = $decision['action'] === 'review';
        $conversation->update([
            'status' => $review ? 'human' : 'ai',
            'intent' => 'social_comment',
            'priority' => $review ? 'high' : 'normal',
            'handoff_reason' => $review ? $decision['reason'] : null,
            'handoff_summary' => $review ? 'This public comment needs human judgment before any response is posted.' : null,
            'outcome' => $review ? 'human_handoff' : 'no_reply_needed',
            'last_message_at' => now(),
        ]);
        $record->update([
            'conversation_id' => $conversation->id,
            'message_id' => $customer->id,
            'status' => 'processed',
            'payload' => $this->minimalPayload($record),
            'processed_at' => now(),
        ]);
    }

    private function hasEarlierPendingInbound(ChannelMessage $record): bool
    {
        return ChannelMessage::query()
            ->where('channel_connection_id', $record->channel_connection_id)
            ->where('direction', 'inbound')
            ->where('provider_sender_id', $record->provider_sender_id)
            ->whereKeyNot($record->id)
            ->whereIn('status', ['received', 'processing'])
            ->where(function ($query) use ($record): void {
                if ($record->received_at) {
                    $query->where('received_at', '<', $record->received_at)
                        ->orWhere(function ($sameTime) use ($record): void {
                            $sameTime->where('received_at', $record->received_at)
                                ->where('id', '<', $record->id);
                        });

                    return;
                }

                $query->where('id', '<', $record->id);
            })
            ->exists();
    }

    private function customerImageRecord(ChannelMessage $record): ?ChannelMessage
    {
        $hasUsableImage = fn (ChannelMessage $candidate): bool => collect(data_get($candidate->payload, 'attachments', []))
            ->contains(fn ($attachment): bool => data_get($attachment, 'type') === 'image'
                && is_string(data_get($attachment, 'url'))
                && trim((string) data_get($attachment, 'url')) !== '');
        if ($hasUsableImage($record)) {
            return $record;
        }
        if ($record->message_type === 'attachment') {
            return null;
        }

        $previous = ChannelMessage::query()
            ->where('channel_connection_id', $record->channel_connection_id)
            ->where('direction', 'inbound')
            ->where('provider_sender_id', $record->provider_sender_id)
            ->where('id', '<', $record->id)
            ->latest('id')
            ->first();
        if (! $previous || $previous->message_type !== 'attachment') {
            $previous = null;
        }
        if ($previous
            && (! $record->received_at
                || ! $previous->received_at
                || ! $previous->received_at->lt($record->received_at->copy()->subMinutes(10)))
            && $hasUsableImage($previous)) {
            return $previous;
        }

        $following = ChannelMessage::query()
            ->where('channel_connection_id', $record->channel_connection_id)
            ->where('direction', 'inbound')
            ->where('provider_sender_id', $record->provider_sender_id)
            ->where('id', '>', $record->id)
            ->oldest('id')
            ->first();
        if (! $following || $following->message_type !== 'attachment') {
            return null;
        }
        if ($record->received_at && $following->received_at
            && $following->received_at->gt($record->received_at->copy()->addSeconds(self::META_SPLIT_MEDIA_WINDOW_SECONDS))) {
            return null;
        }

        return $hasUsableImage($following) ? $following : null;
    }

    private function customerTextForImage(ChannelMessage $imageRecord): ?ChannelMessage
    {
        $previous = ChannelMessage::query()
            ->where('channel_connection_id', $imageRecord->channel_connection_id)
            ->where('direction', 'inbound')
            ->where('provider_sender_id', $imageRecord->provider_sender_id)
            ->where('id', '<', $imageRecord->id)
            ->latest('id')
            ->first();
        if ($previous
            && $previous->message_type !== 'attachment'
            && ! in_array($previous->status, ['processed', 'ignored'], true)
            && (! $previous->received_at
                || ! $imageRecord->received_at
                || ! $previous->received_at->lt($imageRecord->received_at->copy()->subSeconds(self::META_SPLIT_MEDIA_WINDOW_SECONDS)))
            && filled(data_get($previous->payload, 'text'))) {
            return $previous;
        }

        $next = ChannelMessage::query()
            ->where('channel_connection_id', $imageRecord->channel_connection_id)
            ->where('direction', 'inbound')
            ->where('provider_sender_id', $imageRecord->provider_sender_id)
            ->where('id', '>', $imageRecord->id)
            ->oldest('id')
            ->first();
        if (! $next || $next->message_type === 'attachment' || in_array($next->status, ['processed', 'ignored'], true)) {
            return null;
        }
        if ($next->received_at && $imageRecord->received_at && $next->received_at->gt($imageRecord->received_at->copy()->addMinutes(10))) {
            return null;
        }

        return filled(data_get($next->payload, 'text')) ? $next : null;
    }

    private function replyFromCustomerImage(
        ChannelMessage $record,
        ChannelMessage $imageRecord,
        ChannelConnection $connection,
        string $senderId,
        string $text,
        CustomerImageCatalogMatcher $matcher,
        ChannelMessageDispatcher $dispatcher,
    ): void {
        $customerId = "meta:{$connection->provider}:{$connection->id}:{$senderId}";
        $conversation = $connection->agent->conversations()
            ->where('visitor_id', $customerId)
            ->where('channel', $connection->provider)
            ->whereIn('status', ['ai', 'open', 'human'])
            ->latest('id')
            ->first() ?? $connection->agent->conversations()->create([
                'visitor_id' => $customerId,
                'customer_name' => ucfirst($connection->provider).' customer',
                'channel' => $connection->provider,
                'status' => 'ai',
            ]);
        $conversation->update([
            'channel_connection_id' => $connection->id,
            'external_thread_id' => $senderId,
            'last_message_at' => now(),
        ]);

        if ($imageRecord->id !== $record->id) {
            $imageCustomer = $conversation->messages()->firstOrCreate(
                ['request_id' => $imageRecord->idempotency_key],
                ['role' => 'customer', 'content' => '[Customer sent an image.]', 'metadata' => [
                    'attachment_received' => true,
                    'image_received' => true,
                ]],
            );
            $imageRecord->update(['conversation_id' => $conversation->id, 'message_id' => $imageCustomer->id]);
        }
        $caption = str_starts_with($text, '[Customer sent an image') ? '[Customer sent an image.]' : PrivacyRedactor::text($text);
        $customer = $conversation->messages()->firstOrCreate(
            ['request_id' => $record->idempotency_key],
            ['role' => 'customer', 'content' => $caption, 'metadata' => [
                'attachment_received' => true,
                'image_received' => true,
            ]],
        );
        $record->update(['conversation_id' => $conversation->id, 'message_id' => $customer->id]);

        if ($conversation->status === 'human') {
            $this->finishImageRecords($record, $imageRecord, 'human', []);

            return;
        }

        $imageUrl = (string) collect(data_get($imageRecord->payload, 'attachments', []))
            ->first(fn ($attachment): bool => data_get($attachment, 'type') === 'image' && filled(data_get($attachment, 'url')))['url'];
        $started = microtime(true);
        try {
            $result = $matcher->resolve($connection->agent, $conversation, $imageUrl, $text);
        } catch (\Throwable $exception) {
            report($exception);
            $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $text."\n".$conversation->messages()->latest('id')->limit(6)->pluck('content')->implode("\n")) === 1;
            $result = [
                'status' => 'failed',
                'text' => $georgian
                    ? 'ფოტოს ამჯერად სანდოდ დამუშავება ვერ მოვახერხე. შეგიძლიათ ცოტა მოგვიანებით თავიდან გამოგზავნოთ ან პროდუქტის დასახელება ტექსტურად მომწეროთ.'
                    : 'I could not process the photo reliably this time. Please try sending it again later or send the product name as text.',
                'products' => [],
                'product_ids' => [],
                'tools_used' => [],
                'model' => (string) config('services.openai.image_recognition_model', 'gpt-5.6-sol'),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'requests' => 0],
            ];
        }

        // Image recognition can take long enough for an operator to answer in
        // Meta meanwhile. Human ownership always wins: discard the late AI
        // result before it can become a contradictory customer reply.
        $conversation->refresh();
        if ($conversation->status === 'human') {
            $this->finishImageRecords($record, $imageRecord, 'human', []);

            return;
        }

        $assistant = $conversation->messages()->firstOrCreate(
            ['request_id' => $this->derivedRequestId('image-recognition', $imageRecord->idempotency_key)],
            ['role' => 'assistant', 'content' => $result['text'], 'confidence' => in_array($result['status'], ['available', 'unavailable', 'not_found'], true) ? 1 : .8, 'metadata' => [
                'intent' => 'image_product_lookup',
                'products' => $result['products'] ?? [],
                'tools_used' => collect($result['tools_used'] ?? [])->pluck('name')->unique()->values()->all(),
                'image_recognition_status' => $result['status'],
                'image_recognition_model' => $result['model'] ?? null,
                'image_recognition_failed' => $result['status'] === 'failed',
            ]],
        );
        $productIds = collect($result['product_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        if ($productIds->isNotEmpty()) {
            $context = (array) $conversation->context;
            $context['last_catalog_product_ids'] = $productIds->all();
            $conversation->update(['context' => $context, 'intent' => 'image_product_lookup']);
        }

        $usage = (array) ($result['usage'] ?? []);
        AgentRun::create([
            'agent_id' => $connection->agent->id,
            'conversation_id' => $conversation->id,
            'model' => (string) ($result['model'] ?? config('services.openai.image_recognition_model')),
            'route' => 'image_catalog_match',
            'status' => $result['status'] === 'failed' ? 'failed' : 'completed',
            'tools_used' => $result['tools_used'] ?? [],
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
        $this->finishImageRecords($record, $imageRecord, (string) $result['status'], $productIds->all());
        $this->discloseAiIdentityOnFirstReply($assistant, $conversation, $connection->agent, $text);
        $dispatcher->dispatch($assistant);
    }

    private function finishImageRecords(ChannelMessage $record, ChannelMessage $imageRecord, string $status, array $productIds): void
    {
        foreach (collect([$record, $imageRecord])->unique('id') as $message) {
            $payload = $this->minimalPayload($message);
            $payload['image_recognition_status'] = $status;
            if ($productIds !== []) {
                $payload['image_product_ids'] = $productIds;
            }
            $message->update(['status' => 'processed', 'payload' => $payload, 'processed_at' => now()]);
        }
    }

    private function immediatelyFollowsCustomerImage(ChannelMessage $record): bool
    {
        $previous = ChannelMessage::query()
            ->where('channel_connection_id', $record->channel_connection_id)
            ->where('direction', 'inbound')
            ->where('provider_sender_id', $record->provider_sender_id)
            ->where('id', '<', $record->id)
            ->latest('id')
            ->first();

        if (! $previous || $previous->message_type !== 'attachment') {
            return false;
        }

        return true;
    }

    private function discloseAiIdentityOnFirstReply(
        Message $assistant,
        Conversation $conversation,
        Agent $agent,
        string $customerText,
    ): void {
        $hasEarlierAssistantReply = $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '!=', $assistant->id)
            ->exists();
        if ($hasEarlierAssistantReply) {
            return;
        }

        $content = trim((string) $assistant->content);
        $lower = Str::lower($content);
        if (Str::contains($lower, ['ai assistant', 'ai ასისტენტ'])) {
            return;
        }

        $assistantName = $agent->assistantDisplayName();
        $businessName = trim((string) $agent->business_name);
        $isGeorgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $customerText) === 1;
        $disclosure = $isGeorgian
            ? "გამარჯობა! მე ვარ {$assistantName} — {$businessName}-ის AI ასისტენტი 🤖"
            : "Hi! I'm {$assistantName}, {$businessName}'s AI assistant 🤖";

        $assistant->update(['content' => $disclosure.($content !== '' ? "\n\n{$content}" : '')]);
    }

    private function replyThatAttachmentCannotBeRead(
        ChannelMessage $record,
        ChannelConnection $connection,
        string $senderId,
        string $text,
        ChannelMessageDispatcher $dispatcher,
    ): void {
        $assistant = DB::transaction(function () use ($record, $connection, $senderId, $text): ?Message {
            $customerId = "meta:{$connection->provider}:{$connection->id}:{$senderId}";
            $conversation = $connection->agent->conversations()
                ->where('visitor_id', $customerId)
                ->where('channel', $connection->provider)
                ->whereIn('status', ['ai', 'open', 'human'])
                ->latest('id')
                ->first() ?? $connection->agent->conversations()->create([
                    'visitor_id' => $customerId,
                    'customer_name' => ucfirst($connection->provider).' customer',
                    'channel' => $connection->provider,
                    'status' => 'ai',
                ]);
            $caption = str_starts_with($text, '[Customer sent an image') ? '[Customer sent an image.]' : PrivacyRedactor::text($text);
            $isImage = collect(data_get($record->payload, 'attachments', []))
                ->contains(fn ($attachment): bool => data_get($attachment, 'type') === 'image')
                || $this->immediatelyFollowsCustomerImage($record);
            $customer = $conversation->messages()->firstOrCreate(
                ['request_id' => $record->idempotency_key],
                ['role' => 'customer', 'content' => $caption, 'metadata' => [
                    'attachment_received' => true,
                    'image_received' => $isImage,
                ]],
            );
            $conversation->update([
                'channel_connection_id' => $connection->id,
                'external_thread_id' => $senderId,
                'last_message_at' => now(),
                'context' => collect((array) $conversation->context)
                    ->except(['active_catalog_scope', 'last_catalog_product_ids'])
                    ->all(),
            ]);
            $record->update([
                'conversation_id' => $conversation->id,
                'message_id' => $customer->id,
                'status' => 'processed',
                'payload' => $this->minimalPayload($record),
                'processed_at' => now(),
            ]);
            if ($conversation->status === 'human') {
                return null;
            }

            $recentLimitation = $conversation->messages()
                ->where('role', 'assistant')
                ->where('created_at', '>=', now()->subSeconds(30))
                ->latest('id')
                ->first();
            if ((bool) data_get($recentLimitation?->metadata, 'attachment_unavailable')) {
                return null;
            }

            $context = $conversation->messages()->latest('id')->limit(8)->pluck('content')->implode("\n");
            $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $text."\n".$context) === 1;
            $reply = match (true) {
                $georgian && $isImage => 'ბოდიში, ფოტოს შინაარსის სანდოდ ამოცნობა არ შემიძლია. მომწერეთ პროდუქტის სათაური, ავტორი, ბრენდი ან მოდელი ტექსტურად და კატალოგში ზუსტად გადავამოწმებ.',
                $georgian => 'ბოდიში, გამოგზავნილი ფაილის შინაარსის წაკითხვა არ შემიძლია. მომწერეთ მოთხოვნა ტექსტურად და დაგეხმარებით.',
                $isImage => 'Sorry, I cannot reliably recognize the contents of photos. Please send the product title, author, brand, or model as text and I will check the catalog accurately.',
                default => 'Sorry, I cannot read the contents of the attached file. Please send your request as text and I will help.',
            };

            return $conversation->messages()->firstOrCreate(
                ['request_id' => $this->derivedRequestId('image-unavailable', $record->idempotency_key)],
                ['role' => 'assistant', 'content' => $reply, 'confidence' => 1, 'metadata' => [
                    'attachment_unavailable' => true,
                    'image_recognition_unavailable' => $isImage,
                ]],
            );
        }, 3);

        if ($assistant) {
            $dispatcher->dispatch($assistant);
        }
    }

    private function derivedRequestId(string $purpose, string $idempotencyKey): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, "legatus:meta:{$purpose}:{$idempotencyKey}")->toString();
    }

    public function failed(?\Throwable $exception): void
    {
        $reason = 'Inbound Meta message processing failed after safe retries.';

        $record = ChannelMessage::query()->find($this->channelMessageId);
        if ($record) {
            $originalText = trim((string) data_get($record->payload, 'text', ''));
            $isImage = $record->message_type === 'attachment'
                && collect(data_get($record->payload, 'attachments', []))
                    ->contains(fn ($attachment): bool => data_get($attachment, 'type') === 'image');
            $preserved = $this->preserveWithoutPausingAi($record, $reason);
            $updates = [
                'status' => 'failed',
                'failure_reason' => $reason,
                'failed_at' => now(),
            ];
            if ($preserved) {
                $updates['payload'] = $this->minimalPayload($record);
            }
            $record->update($updates);
            if ($preserved) {
                $record->refresh();
                $this->replyAfterFinalFailure($record, $originalText, $isImage);
            }
        }
    }

    private function replyAfterFinalFailure(ChannelMessage $record, string $text, bool $isImage): void
    {
        if ($record->message_type === 'comment') {
            return;
        }

        try {
            $record->loadMissing(['connection.agent', 'conversation']);
            $connection = $record->connection;
            $conversation = $record->conversation;
            if (! $connection || ! $connection->isActive() || ! $conversation || $conversation->status === 'human') {
                return;
            }

            $recentFailureReply = $conversation->messages()
                ->where('role', 'assistant')
                ->where('created_at', '>=', now()->subSeconds(30))
                ->latest('id')
                ->get()
                ->first(fn (Message $message): bool => (bool) data_get($message->metadata, 'automatic_processing_failure'));
            if ($recentFailureReply) {
                return;
            }

            $context = $conversation->messages()->latest('id')->limit(8)->pluck('content')->implode("\n");
            $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $text."\n".$context) === 1;
            $reply = match (true) {
                $georgian && $isImage => 'ფოტოს დამუშავება ამჯერად ტექნიკური შეფერხების გამო ვერ მოვასწარი. შეგიძლიათ თავიდან გამოგვიგზავნოთ და ხელახლა შევამოწმებ.',
                $georgian => 'პასუხის მომზადება ტექნიკური შეფერხების გამო ვერ მოვასწარი. გთხოვთ, შეტყობინება თავიდან გამოგვიგზავნოთ.',
                $isImage => 'I could not finish processing the photo because of a technical issue. Please send it again and I will check it again.',
                default => 'I could not finish preparing the reply because of a technical issue. Please send your message again.',
            };
            $assistant = $conversation->messages()->firstOrCreate(
                ['request_id' => $this->derivedRequestId('processing-failure', $record->idempotency_key)],
                ['role' => 'assistant', 'content' => $reply, 'confidence' => 1, 'metadata' => [
                    'automatic_processing_failure' => true,
                    'image_recognition_failed' => $isImage,
                ]],
            );

            $conversation->refresh();
            if ($conversation->status === 'human') {
                $assistant->delete();

                return;
            }

            $this->discloseAiIdentityOnFirstReply($assistant, $conversation, $connection->agent, $text);
            app(ChannelMessageDispatcher::class)->dispatch($assistant);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** Preserve a redacted customer request without inventing human ownership. */
    private function preserveWithoutPausingAi(
        ChannelMessage $record,
        string $reason,
        bool $transportFailure = true,
        bool $automaticProcessingFailure = true,
    ): bool {
        try {
            return DB::transaction(function () use ($record, $reason, $transportFailure, $automaticProcessingFailure): bool {
                $locked = ChannelMessage::query()->lockForUpdate()->find($record->id);
                if (! $locked) {
                    return false;
                }

                $connection = ChannelConnection::query()->with('agent')->lockForUpdate()->find($locked->channel_connection_id);
                $senderId = trim((string) $locked->provider_sender_id);
                $text = trim((string) data_get($locked->payload, 'text', ''));
                if (! $connection || ! $connection->agent || $senderId === '' || $text === '') {
                    return false;
                }

                $isComment = $locked->message_type === 'comment';
                $postId = trim((string) data_get($locked->payload, 'post_id', ''));
                $customerId = $isComment
                    ? "meta-comment:{$connection->provider}:{$connection->id}:".hash('sha256', $senderId.':'.$postId)
                    : "meta:{$connection->provider}:{$connection->id}:{$senderId}";
                $channel = $isComment ? $connection->provider.'_comment' : $connection->provider;
                $conversation = $connection->agent->conversations()
                    ->where('visitor_id', $customerId)
                    ->where('channel', $channel)
                    ->whereIn('status', ['ai', 'open', 'human'])
                    ->latest('id')
                    ->first() ?? $connection->agent->conversations()->create([
                        'visitor_id' => $customerId,
                        'customer_name' => ucfirst($connection->provider).($isComment ? ' commenter' : ' customer'),
                        'channel' => $channel,
                        'status' => 'ai',
                    ]);

                $safeText = PrivacyRedactor::text($text);
                $metadata = [
                    'contact_evidence' => PrivacyRedactor::contactEvidence($text),
                    'channel_message_id' => $locked->id,
                ];
                if ($automaticProcessingFailure) {
                    $metadata['automatic_processing_failure'] = true;
                    $metadata['processing_failure_reason'] = $reason;
                } else {
                    $metadata['automated_reply_suppressed'] = true;
                    $metadata['suppression_reason'] = $reason;
                }
                if ($transportFailure) {
                    $metadata['transport_failure'] = true;
                }
                if ($safeText !== $text) {
                    $metadata['pii_redacted'] = true;
                }

                $customerMessage = $conversation->messages()->firstOrCreate(
                    ['request_id' => $locked->idempotency_key],
                    ['role' => 'customer', 'content' => $safeText, 'metadata' => $metadata],
                );
                $conversationUpdates = [
                    'channel_connection_id' => $connection->id,
                    'external_thread_id' => $isComment ? (string) $locked->provider_message_id : $senderId,
                    'last_message_at' => now(),
                ];
                if ($isComment) {
                    $context = is_array($conversation->context) ? $conversation->context : [];
                    $context += [
                        'source_type' => 'social_comment',
                        'provider' => $connection->provider,
                        'post_id' => $postId,
                        'reply_mode' => 'public_comment',
                    ];
                    $conversationUpdates += [
                        'status' => 'human',
                        'priority' => 'high',
                        'intent' => 'social_comment',
                        'handoff_reason' => 'Automatic public comment processing failed safely. Review before replying.',
                        'context' => $context,
                    ];
                }
                if (! $isComment && $conversation->status !== 'human') {
                    $conversationUpdates += [
                        'status' => 'ai',
                        'assigned_to' => null,
                        'priority' => 'normal',
                        'intent' => null,
                        'handoff_reason' => null,
                        'handoff_summary' => null,
                        'suggested_reply' => null,
                        'outcome' => null,
                        'resolved_at' => null,
                    ];
                }
                $conversation->update($conversationUpdates);
                $locked->update([
                    'conversation_id' => $conversation->id,
                    'message_id' => $customerMessage->id,
                ]);

                return true;
            }, 3);
        } catch (\Throwable) {
            // Retain the encrypted payload for a later/manual recovery rather
            // than erasing the only copy of the customer's request.
            return false;
        }
    }

    private function minimalPayload(ChannelMessage $record): array
    {
        return array_filter([
            'provider_timestamp' => data_get($record->payload, 'provider_timestamp'),
            'attachment_types' => collect(data_get($record->payload, 'attachments', []))
                ->pluck('type')
                ->filter()
                ->unique()
                ->take(5)
                ->values()
                ->all(),
            'content_removed' => true,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
