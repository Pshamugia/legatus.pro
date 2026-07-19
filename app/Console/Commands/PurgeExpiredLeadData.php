<?php

namespace App\Console\Commands;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RecommendationEvent;
use App\Models\ShoppingProfile;
use App\Support\PrivacyRedactor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PurgeExpiredLeadData extends Command
{
    protected $signature = 'legatus:purge-expired-data';

    protected $description = 'Remove expired lead contact details while retaining anonymized outcome metrics';

    public function handle(): int
    {
        $count = 0;
        $failed = 0;

        Lead::whereNotNull('retention_until')
            ->where('retention_until', '<=', now())
            ->chunkById(100, function ($leads) use (&$count, &$failed): void {
                foreach ($leads as $candidate) {
                    try {
                        $processed = DB::transaction(fn (): bool => $this->purgeLead($candidate->id));
                        if ($processed) {
                            $count++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                        $this->error("Failed to anonymize expired lead #{$candidate->id}; it remains eligible for retry.");
                    }
                }
            });

        $this->info("Anonymized {$count} expired lead record(s) and their retained traces.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function purgeLead(int $leadId): bool
    {
        $lead = Lead::whereKey($leadId)->lockForUpdate()->first();
        if (! $lead || ! $lead->retention_until || $lead->retention_until->isAfter(now())) {
            return false;
        }

        $conversationId = $lead->conversation_id;
        if ($conversationId) {
            $conversation = Conversation::whereKey($conversationId)->lockForUpdate()->first();

            AgentRun::where('conversation_id', $conversationId)->chunkById(100, function ($runs): void {
                $runs->each(function (AgentRun $run): void {
                    $tools = PrivacyRedactor::toolTrace($run->tools_used ?? []);
                    $error = $run->error ? PrivacyRedactor::text($run->error) : null;
                    if ($tools !== ($run->tools_used ?? []) || $error !== $run->error) {
                        $run->update(['tools_used' => $tools, 'error' => $error]);
                    }
                });
            });

            Message::where('conversation_id', $conversationId)->chunkById(100, function ($messages): void {
                $messages->each(function (Message $message): void {
                    $content = PrivacyRedactor::text($message->content);
                    $metadata = PrivacyRedactor::structured($message->metadata ?? []);
                    if ($content !== $message->content || $metadata !== ($message->metadata ?? [])) {
                        $message->update([
                            'content' => $content,
                            'metadata' => array_merge($metadata, ['pii_redacted' => true]),
                        ]);
                    }
                });
            });

            RecommendationEvent::where('conversation_id', $conversationId)->chunkById(100, function ($events): void {
                $events->each(function (RecommendationEvent $event): void {
                    $query = PrivacyRedactor::structured($event->query ?? []);
                    $ranked = PrivacyRedactor::structured($event->ranked_products ?? []);
                    if ($query !== ($event->query ?? []) || $ranked !== ($event->ranked_products ?? [])) {
                        $event->update(['query' => $query, 'ranked_products' => $ranked]);
                    }
                });
            });

            ShoppingProfile::where('conversation_id', $conversationId)->delete();

            if ($conversation) {
                $conversation->update([
                    'customer_name' => null,
                    'context' => PrivacyRedactor::structured($conversation->context ?? []),
                    'handoff_reason' => $conversation->handoff_reason ? PrivacyRedactor::text($conversation->handoff_reason) : null,
                    'handoff_summary' => $conversation->handoff_summary ? PrivacyRedactor::text($conversation->handoff_summary) : null,
                    'suggested_reply' => $conversation->suggested_reply ? PrivacyRedactor::text($conversation->suggested_reply) : null,
                ]);
            }
        }

        $lead->update([
            'name' => null,
            'email' => null,
            'phone' => null,
            'notes' => 'Contact details removed by the 90-day retention policy.',
            'consent_at' => null,
            'retention_until' => null,
        ]);

        return true;
    }
}
