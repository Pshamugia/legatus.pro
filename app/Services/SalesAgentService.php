<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Support\PrivacyRedactor;
use Illuminate\Support\Str;

class SalesAgentService
{
    public function __construct(private OpenAiSalesOrchestrator $orchestrator, private SalesToolbox $tools) {}

    public function reply(Agent $agent, string $message, ?Conversation $conversation = null): array
    {
        if (config('services.openai.key') && $conversation) {
            if ($this->quotaExceeded($agent)) {
                return $this->failClosed($agent, $conversation, $message, 'The workspace AI usage limit has been reached.');
            }

            try {
                return $this->orchestrator->respond($agent, $conversation, $message);
            } catch (\Throwable $e) {
                report($e);

                return $this->failClosed($agent, $conversation, $message, 'The AI provider or verification workflow failed safely.', $e);
            }
        }

        if (! config('legatus.offline_fallback_enabled')) {
            return $this->failClosed($agent, $conversation, $message, 'The verified AI service is unavailable.');
        }

        $reply = $this->fallback($agent, $message, $conversation);
        $threshold = (float) ($agent->settings['handoff_threshold'] ?? .72);
        if ($reply['confidence'] < $threshold) {
            $reply = ['text' => 'ამ პასუხის სანდოდ დადასტურება ვერ შევძელი, ამიტომ საუბარს ოპერატორს გადავცემ.', 'intent' => 'handoff', 'confidence' => $reply['confidence'], 'handoff' => true, 'products' => [], 'escalation_reason' => 'Fallback confidence is below the configured handoff threshold.'];
        }
        if ($conversation && $reply['handoff']) {
            $conversation->update([
                'status' => 'human',
                'handoff_reason' => $reply['escalation_reason'] ?? 'Customer requested a human operator.',
                'handoff_summary' => 'The customer needs human assistance. The complete transcript is preserved.',
                'suggested_reply' => 'გამარჯობა! მე ვარ ოპერატორი და საუბარი უკვე გადავხედე. რით შემიძლია დაგეხმაროთ?',
                'outcome' => 'human_handoff',
            ]);
        }
        if ($conversation) {
            AgentRun::create(['agent_id' => $agent->id, 'conversation_id' => $conversation->id, 'provider' => 'local', 'model' => 'deterministic-fallback', 'status' => 'fallback', 'tools_used' => [['name' => 'local_fallback']]]);
        }

        $sources = collect($reply['products'] ?? [])->isNotEmpty()
            ? [['label' => 'Verified local catalog', 'type' => 'catalog', 'updated_at' => $agent->products()->max('updated_at')]]
            : [];

        return $reply + ['escalation_reason' => null, 'sources' => $sources, 'tools_used' => ['local_fallback']];
    }

    private function quotaExceeded(Agent $agent): bool
    {
        $runs = AgentRun::where('agent_id', $agent->id)
            ->where('provider', 'openai')
            ->where('created_at', '>=', now()->startOfDay());

        if ((clone $runs)->count() >= max(1, (int) config('legatus.daily_ai_run_limit'))) {
            return true;
        }

        return (int) (clone $runs)->sum('input_tokens') + (int) (clone $runs)->sum('output_tokens')
            >= max(1000, (int) config('legatus.daily_ai_token_limit'));
    }

    private function failClosed(Agent $agent, ?Conversation $conversation, string $customerMessage, string $reason, ?\Throwable $error = null): array
    {
        if ($conversation) {
            $conversation->update([
                'status' => 'human',
                'handoff_reason' => $reason,
                'handoff_summary' => 'Legatus stopped before returning an unverified answer. The customer request and safe failure reason are available to the operator.',
                'suggested_reply' => 'I am reviewing your request now and will reply with verified information shortly.',
                'outcome' => 'human_handoff',
                'last_message_at' => now(),
            ]);
            AgentRun::create([
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'model' => config('services.openai.model'),
                'status' => 'failed',
                'tools_used' => [['name' => 'fail_closed_handoff']],
                'error' => PrivacyRedactor::text(Str::limit($error?->getMessage() ?? $reason, 1000)),
            ]);
        }

        $text = preg_match('/[\x{10A0}-\x{10FF}]/u', $customerMessage)
            ? 'გადამოწმებული პასუხის დაბრუნება ამჯამად ვერ მოხერხდა. საუბარს ოპერატორს გადავცემ, რათა ვარაუდით არ გიპასუხოთ.'
            : 'I could not return a verified answer right now, so I handed the conversation to a human instead of guessing.';

        return ['text' => $text, 'intent' => 'handoff', 'confidence' => 1.0, 'handoff' => true, 'escalation_reason' => $reason, 'products' => [], 'sources' => [], 'tools_used' => ['fail_closed_handoff']];
    }

    private function fallback(Agent $agent, string $message, ?Conversation $conversation = null): array
    {
        $text = Str::lower($message);
        $products = $agent->products()->where('is_active', true)->get();
        $matched = $products->filter(fn ($p) => Str::contains($text, Str::lower($p->name)) || ($p->category && Str::contains($text, Str::lower($p->category))));
        if (Str::contains($text, ['ignore previous', 'ignore all instructions', 'system prompt', 'developer message', 'წინა ინსტრუქციები დაივიწყე'])) {
            return ['text' => 'ბიზნესის პროდუქციასა და მომსახურებაზე დაგეხმარებით, მაგრამ შიდა ინსტრუქციებს ან საიდუმლო მონაცემებს არ ვამჟღავნებ. რას ეძებთ?', 'intent' => 'discovery', 'confidence' => .99, 'handoff' => false, 'products' => []];
        }
        if (Str::contains($text, ['ადამიან', 'ოპერატორ', 'კონსულტანტ', 'human', 'manager', 'რთული'])) {
            return ['text' => 'რა თქმა უნდა — საუბარს ახლა ჩვენს კონსულტანტს გადავცემ. კონტექსტი შენახულია და ყველაფრის თავიდან ახსნა არ დაგჭირდებათ. 🙌', 'intent' => 'handoff', 'confidence' => .99, 'handoff' => true, 'products' => []];
        }
        if (Str::contains($text, ['ფასდაკლება', 'discount', '%'])) {
            preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $text, $discountMatch);
            $requested = isset($discountMatch[1]) ? (float) str_replace(',', '.', $discountMatch[1]) : null;
            $allowed = (float) ($agent->settings['discount_limit'] ?? 0);
            if ($requested !== null && $requested > $allowed) {
                return ['text' => "{$requested}%-იანი ფასდაკლება {$allowed}%-იან ავტონომიურ ლიმიტს აღემატება, ამიტომ მოთხოვნას მენეჯერს გადავცემ.", 'intent' => 'handoff', 'confidence' => .99, 'handoff' => true, 'products' => [], 'escalation_reason' => 'Discount approval required.'];
            }

            return ['text' => 'შეთავაზების ზუსტად გამოსათვლელად მითხარით პროდუქტი და რაოდენობა.', 'intent' => 'offer', 'confidence' => .90, 'handoff' => false, 'products' => []];
        }
        if (Str::contains($text, ['საბითუმო', 'wholesale', 'bulk'])) {
            return ['text' => 'დიახ, საბითუმო პირობები გვაქვს. რაოდენობის მიხედვით სპეციალურ შეთავაზებას მოგიმზადებთ. დაახლოებით რამდენ ერთეულს გეგმავთ?', 'intent' => 'wholesale', 'confidence' => .91, 'handoff' => false, 'products' => []];
        }
        if (Str::contains($text, ['ხვალ', 'მიწოდება', 'delivery', 'ჩამომივა'])) {
            if ($conversation) {
                $language = preg_match('/[\x{10A0}-\x{10FF}]/u', $message) ? 'ka' : 'en';
                $delivery = $this->tools->execute('calculate_delivery', ['city' => $message, 'language' => $language], $agent, $conversation);
                if ($delivery['ok'] ?? false) {
                    return ['text' => $delivery['customer_message'], 'intent' => 'delivery', 'confidence' => .99, 'handoff' => false, 'products' => [], 'sources' => [$delivery['source']], 'tools_used' => ['calculate_delivery']];
                }
            }

            return ['text' => 'მიწოდების ვადი ამჯამად გადამოწმებული პოლიტიკით ვერ დავადასტურე. ვარაუდის ნაცვლად საუბარს ოპერატორს გადავცემ.', 'intent' => 'handoff', 'confidence' => .30, 'handoff' => true, 'products' => [], 'escalation_reason' => 'Verified delivery policy is unavailable in offline mode.'];
        }
        if (Str::contains($text, ['მარაგ', 'stock', 'available'])) {
            $selection = $matched->isNotEmpty() ? $matched : $products->take(3);
            $summary = $selection->map(fn ($product) => $product->stock > 0 ? "{$product->name} — მარაგში {$product->stock} ცალია" : "{$product->name} — ამოიწურა")->join(', ');

            return ['text' => $summary ?: 'რომელი პროდუქტის მარაგი გაინტერესებთ?', 'intent' => 'stock', 'confidence' => .97, 'handoff' => false, 'products' => $selection->values()];
        }
        if (Str::contains($text, ['ჰგავს', 'მირჩიე', 'ვეძებ', 'recommend', 'მსგავს'])) {
            preg_match('/(\d+(?:[.,]\d+)?)\s*(?:₾|ლარ|gel)/iu', $text, $budgetMatch);
            $budget = isset($budgetMatch[1]) ? (float) str_replace(',', '.', $budgetMatch[1]) : null;
            $selection = $products->where('stock', '>', 0)->when($budget !== null, fn ($items) => $items->filter(fn ($product) => (float) $product->price <= $budget))->take(3)->values();

            return ['text' => 'ეს ვარიანტები შევარჩიე მოთხოვნის, ბიუჯეტისა და რეალური ხელმისაწვდომობის მიხედვით. უფრო დრამატული გსურთ თუ მსუბუქი და სწრაფად წასაკითხი?', 'intent' => 'recommendation', 'confidence' => .90, 'handoff' => false, 'products' => $selection];
        }
        if (Str::contains($text, ['ფასი', 'ღირს', 'price'])) {
            $selection = $matched->isNotEmpty() ? $matched : $products->take(3);
            $summary = $selection->map(fn ($p) => "{$p->name} — {$p->price} ₾")->join(', ');

            return ['text' => $summary ? "რა თქმა უნდა: {$summary}. რომელი დაგაინტერესათ?" : 'რომელი პროდუქტის ფასი გაინტერესებთ?', 'intent' => 'price', 'confidence' => .96, 'handoff' => false, 'products' => $selection->values()];
        }

        $introduction = $agent->hasCustomAssistantName()
            ? "მე ვარ {$agent->assistantDisplayName()} — {$agent->business_name}-ის AI ასისტენტი."
            : "მე ვარ {$agent->business_name}-ის AI ასისტენტი.";

        return ['text' => "გამარჯობა! {$introduction} შემიძლია პროდუქტის შერჩევა, ფასისა და მარაგის შემოწმება, მიწოდების დაზუსტება ან ოპერატორთან დაკავშირება. რას ეძებთ?", 'intent' => 'discovery', 'confidence' => .82, 'handoff' => false, 'products' => []];
    }
}
