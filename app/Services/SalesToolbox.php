<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\Lead;
use App\Models\RecommendationEvent;
use App\Models\Reservation;
use App\Models\ShoppingProfile;
use App\Support\PrivacyRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesToolbox
{
    public function __construct(private EmbeddingService $embeddings) {}

    public function definitions(): array
    {
        return [
            $this->tool('search_products', 'Search the verified product catalog by customer needs.', ['query' => ['type' => 'string'], 'category' => ['type' => ['string', 'null']], 'max_price' => ['type' => ['number', 'null']]], ['query', 'category', 'max_price']),
            $this->tool('search_knowledge', 'Search verified policies and website knowledge.', ['query' => ['type' => 'string']], ['query']),
            $this->tool('save_shopping_preferences', 'Remember customer preferences for this shopping conversation.', ['budget' => ['type' => ['number', 'null']], 'occasion' => ['type' => ['string', 'null']], 'mood' => ['type' => ['string', 'null']], 'likes' => ['type' => 'array', 'items' => ['type' => 'string']], 'dislikes' => ['type' => 'array', 'items' => ['type' => 'string']], 'recipient' => ['type' => ['string', 'null']]], ['budget', 'occasion', 'mood', 'likes', 'dislikes', 'recipient']),
            $this->tool('recommend_products', 'Rank suitable products using customer constraints and verified catalog data.', ['query' => ['type' => 'string'], 'budget' => ['type' => ['number', 'null']], 'category' => ['type' => ['string', 'null']], 'mood' => ['type' => ['string', 'null']], 'occasion' => ['type' => ['string', 'null']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5]], ['query', 'budget', 'category', 'mood', 'occasion', 'limit']),
            $this->tool('compare_products', 'Compare verified attributes for selected products.', ['product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2, 'maxItems' => 4]], ['product_ids']),
            $this->tool('check_stock', 'Read current stock and price for one product.', ['product_id' => ['type' => 'integer'], 'quantity' => ['type' => 'integer', 'minimum' => 1]], ['product_id', 'quantity']),
            $this->tool('calculate_delivery', 'Calculate an indicative delivery window from the business policy and server time.', ['city' => ['type' => 'string'], 'language' => ['type' => 'string', 'enum' => ['ka', 'en']]], ['city', 'language']),
            $this->tool('create_lead', 'Save only contact details the customer explicitly consented to share.', ['name' => ['type' => ['string', 'null']], 'email' => ['type' => ['string', 'null']], 'phone' => ['type' => ['string', 'null']], 'intent' => ['type' => 'string'], 'notes' => ['type' => ['string', 'null']], 'consent' => ['type' => 'boolean']], ['name', 'email', 'phone', 'intent', 'notes', 'consent']),
            $this->tool('request_human', 'Escalate with a clear reason, concise summary, and ready-to-send operator reply.', ['reason' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'suggested_reply' => ['type' => 'string'], 'urgency' => ['type' => 'string', 'enum' => ['normal', 'high']]], ['reason', 'summary', 'suggested_reply', 'urgency']),
            $this->tool('reserve_product', 'Create a short pending reservation; never claim payment or final order.', ['product_id' => ['type' => 'integer'], 'quantity' => ['type' => 'integer', 'minimum' => 1]], ['product_id', 'quantity']),
            $this->tool('build_offer', 'Calculate a non-binding offer. Discounts above the configured limit are blocked and escalated.', ['items' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'properties' => ['product_id' => ['type' => 'integer'], 'quantity' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['product_id', 'quantity'], 'additionalProperties' => false]], 'discount_percent' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100]], ['items', 'discount_percent']),
        ];
    }

    private function tool(string $name, string $description, array $properties, array $required): array
    {
        return ['type' => 'function', 'name' => $name, 'description' => $description, 'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false], 'strict' => true];
    }

    public function execute(string $name, array $args, Agent $agent, Conversation $conversation): array
    {
        return match ($name) {
            'search_products' => $this->search($agent, $conversation, $args), 'search_knowledge' => $this->knowledge($agent, $args), 'save_shopping_preferences' => $this->preferences($conversation, $args), 'recommend_products' => $this->recommend($agent, $conversation, $args), 'compare_products' => $this->compare($agent, $conversation, $args), 'check_stock' => $this->stock($agent, $conversation, $args), 'calculate_delivery' => $this->delivery($agent, $args),
            'create_lead' => $this->lead($agent, $conversation, $args), 'request_human' => $this->handoff($conversation, $args),
            'reserve_product' => $this->reserve($agent, $conversation, $args), 'build_offer' => $this->offer($agent, $conversation, $args),
            default => ['ok' => false, 'error' => 'Unknown tool'],
        };
    }

    private function search(Agent $agent, Conversation $conversation, array $a): array
    {
        $pattern = '%'.$a['query'].'%';
        $q = $agent->products()->where('is_active', true)->where(fn ($query) => $query
            ->whereLike('name', $pattern)
            ->orWhereLike('description', $pattern)
            ->orWhereLike('category', $pattern));
        if ($a['category']) {
            $q->whereLike('category', '%'.$a['category'].'%');
        }
        if ($a['max_price']) {
            $q->where('price', '<=', $a['max_price']);
        }

        $products = $q->limit(30)->get(['id', 'name', 'category', 'description', 'price', 'stock', 'updated_at'])
            ->map(function ($product) use ($conversation): array {
                $available = $this->availableStock($product, $conversation);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'description' => $product->description,
                    'price' => (float) $product->price,
                    'stock' => $available,
                    'available_stock' => $available,
                    'updated_at' => $product->updated_at,
                ];
            })
            ->filter(fn (array $product) => $product['available_stock'] > 0)
            ->take(6)
            ->values();

        return ['ok' => true, 'source' => $this->catalogSource($agent), 'products' => $products->all()];
    }

    private function knowledge(Agent $agent, array $a): array
    {
        $queryText = trim((string) ($a['query'] ?? ''));
        if ($queryText === '') {
            return ['ok' => false, 'error' => 'A specific knowledge question is required.'];
        }

        try {
            $semantic = $this->embeddings->semanticSearch($agent, $queryText);
            if ($semantic) {
                return ['ok' => true, 'method' => 'semantic', 'results' => $semantic];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $stopWords = collect([
            'about', 'are', 'can', 'does', 'for', 'from', 'how', 'is', 'me', 'our', 'policy', 'please', 'rules', 'tell', 'terms', 'the', 'what', 'which', 'your',
            'არის', 'აქვს', 'ჩვენი', 'თქვენი', 'რა', 'როგორ', 'პირობები', 'პოლიტიკა', 'წესი',
        ]);
        $terms = collect(preg_split('/[^\pL\pN]+/u', Str::lower($queryText)))
            ->filter(fn ($term) => mb_strlen($term) > 2 && ! $stopWords->contains($term))
            ->unique()
            ->take(6)
            ->values();
        if ($terms->isEmpty()) {
            return ['ok' => false, 'error' => 'The knowledge question did not contain a specific searchable subject.'];
        }

        $q = KnowledgeChunk::where('agent_id', $agent->id);
        $q->where(function ($query) use ($terms) {
            foreach ($terms as $term) {
                $pattern = '%'.Str::lower($term).'%';
                $query->orWhereRaw('LOWER(content) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(title) LIKE ?', [$pattern]);
            }
        });

        $results = $q->limit(5)
            ->get(['id', 'kind', 'title', 'content', 'metadata'])
            ->map(function ($chunk) use ($terms): array {
                $haystack = Str::lower(implode(' ', [$chunk->title, $chunk->content]));
                $matchedTerms = $terms->filter(fn ($term) => Str::contains($haystack, $term))->values();

                return [
                    'chunk_id' => $chunk->id,
                    'kind' => $chunk->kind,
                    'title' => $chunk->title,
                    'excerpt' => Str::limit($chunk->content, 700),
                    'metadata' => $chunk->metadata,
                    'matched_terms' => $matchedTerms->all(),
                ];
            })
            ->filter(fn (array $result) => $result['matched_terms'] !== [])
            ->values()
            ->all();

        if ($results === []) {
            return ['ok' => false, 'error' => 'No relevant verified knowledge was found for this question.'];
        }

        return ['ok' => true, 'method' => 'lexical', 'results' => $results];
    }

    private function preferences(Conversation $c, array $a): array
    {
        $safe = PrivacyRedactor::structured($a);
        $profile = ShoppingProfile::updateOrCreate(['conversation_id' => $c->id], ['preferences' => $safe]);

        return ['ok' => true, 'profile_id' => $profile->id, 'preferences' => $safe];
    }

    private function recommend(Agent $agent, Conversation $c, array $a): array
    {
        $terms = collect(preg_split('/[^\pL\pN]+/u', Str::lower(implode(' ', array_filter([$a['query'], $a['category'], $a['mood'], $a['occasion']])))))->filter(fn ($x) => mb_strlen($x) > 2)->unique();
        $query = $agent->products()->where('is_active', true);
        if ($a['budget']) {
            $query->where('price', '<=', (float) $a['budget']);
        }
        $ranked = $query->get()->map(function ($p) use ($terms, $a, $c) {
            $haystack = Str::lower(implode(' ', [$p->name, $p->category, $p->description, json_encode($p->metadata, JSON_UNESCAPED_UNICODE)]));
            $matched = $terms->filter(fn ($t) => Str::contains($haystack, $t))->values();
            $within = ! $a['budget'] || (float) $p->price <= (float) $a['budget'];
            $available = $this->availableStock($p, $c);
            $score = $matched->count() * 2 + ($within ? 2 : -5) + ($available > 0 ? 2 : -6);

            return ['id' => $p->id, 'name' => $p->name, 'category' => $p->category, 'description' => $p->description, 'price' => (float) $p->price, 'stock' => $available, 'available_stock' => $available, 'score' => $score, 'matched_signals' => $matched->all(), 'within_budget' => $within];
        })->filter(fn (array $product) => $product['available_stock'] > 0)->sortByDesc('score')->take($a['limit'])->values();
        RecommendationEvent::create(['conversation_id' => $c->id, 'query' => PrivacyRedactor::structured($a), 'ranked_products' => PrivacyRedactor::structured($ranked->all())]);

        return ['ok' => true, 'ranking_method' => 'constraints + catalog signals + availability', 'recommendations' => $ranked->all()];
    }

    private function compare(Agent $agent, Conversation $conversation, array $a): array
    {
        $products = $agent->products()->where('is_active', true)->whereIn('id', $a['product_ids'])->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'category' => $p->category, 'description' => $p->description, 'price' => (float) $p->price, 'stock' => $this->availableStock($p, $conversation), 'metadata' => $p->metadata])->values();

        return ['ok' => true, 'products' => $products, 'comparison_fields' => ['fit', 'category', 'price', 'availability']];
    }

    private function stock(Agent $agent, Conversation $conversation, array $a): array
    {
        $p = $agent->products()->where('is_active', true)->find($a['product_id']);
        $available = $p ? $this->availableStock($p, $conversation) : 0;

        return $p ? ['ok' => true, 'product_id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price, 'catalog_stock' => (int) $p->stock, 'stock' => $available, 'available_stock' => $available, 'available' => $available >= $a['quantity'], 'source' => $this->catalogSource($agent)] : ['ok' => false, 'error' => 'Active product not found'];
    }

    private function delivery(Agent $agent, array $a): array
    {
        $policy = $agent->settings['delivery_policy'] ?? null;
        if (! is_array($policy) || empty($policy['timezone']) || empty($policy['cutoff']) || empty($policy['local_cities'])) {
            return ['ok' => false, 'error' => 'A verified delivery policy is not configured for this business.'];
        }

        try {
            $now = CarbonImmutable::now($policy['timezone']);
            $cutoff = CarbonImmutable::parse($now->toDateString().' '.$policy['cutoff'], $policy['timezone']);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'The configured delivery timezone or cutoff is invalid.'];
        }

        $city = Str::lower(trim($a['city']));
        $isLocal = collect($policy['local_cities'])->contains(fn ($candidate) => Str::contains($city, Str::lower($candidate)));
        $beforeCutoff = $now->isWeekday() && $now->lte($cutoff);
        $minimumDays = $isLocal
            ? (int) ($policy['local_business_days'] ?? 1) + ($beforeCutoff ? 0 : 1)
            : (int) ($policy['regional_min_business_days'] ?? 1) + ($beforeCutoff ? 0 : 1);
        $maximumDays = $isLocal ? $minimumDays : max($minimumDays, (int) ($policy['regional_max_business_days'] ?? 3) + ($beforeCutoff ? 0 : 1));
        $earliest = $this->addBusinessDays($now, max(1, $minimumDays));
        $latest = $this->addBusinessDays($now, max(1, $maximumDays));
        $estimate = $isLocal ? $minimumDays.' business day'.($minimumDays === 1 ? '' : 's') : $minimumDays.'–'.$maximumDays.' business days';
        $estimateKa = $isLocal ? $minimumDays.' სამუშაო დღე' : $minimumDays.'–'.$maximumDays.' სამუშაო დღე';
        $customerMessage = ($a['language'] ?? 'ka') === 'en'
            ? "For {$a['city']}, the verified indicative window is {$earliest->toDateString()} to {$latest->toDateString()} ({$estimate}). Final timing is confirmed after the address and order are confirmed."
            : "{$a['city']}-ში გადამოწმებული სავარაუდო ფანჯარაა {$earliest->toDateString()}-დან {$latest->toDateString()}-მდე ({$estimateKa}). საბოლოო დრო მისამართისა და შეკვეთის დადასტურების შემდეგ დაზუსტდება.";

        return [
            'ok' => true,
            'city' => $a['city'],
            'earliest' => $earliest->toDateString(),
            'latest' => $latest->toDateString(),
            'estimate' => $estimate,
            'timezone' => $policy['timezone'],
            'cutoff' => $policy['cutoff'],
            'order_before_cutoff' => $beforeCutoff,
            'indicative' => true,
            'customer_message' => $customerMessage,
            'source' => ['label' => $policy['source_label'] ?? 'Verified delivery policy', 'type' => 'policy'],
        ];
    }

    private function lead(Agent $agent, Conversation $c, array $a): array
    {
        $email = filter_var($a['email'], FILTER_VALIDATE_EMAIL) ? Str::lower(trim($a['email'])) : null;
        $phone = $this->normalizedPhone($a['phone'] ?? null);
        if (! empty($a['email']) && ! $email) {
            return ['ok' => false, 'error' => 'The supplied email address is invalid.'];
        }
        if (! empty($a['phone']) && ! $phone) {
            return ['ok' => false, 'error' => 'The supplied phone number is invalid.'];
        }
        if (! $email && ! $phone) {
            return ['ok' => false, 'error' => 'A valid email address or phone number is required to create a contact lead.'];
        }
        $consentMessage = $this->consentMessage($c, $email, $phone);
        if (! $a['consent'] || ! $consentMessage) {
            return ['ok' => false, 'error' => 'Explicit consent and the exact contact details are required in the same customer message.'];
        }
        $lead = Lead::updateOrCreate(['conversation_id' => $c->id], [
            'agent_id' => $agent->id,
            'consent_message_id' => $consentMessage->id,
            'name' => $a['name'] ? Str::limit(trim($a['name']), 100, '') : null,
            'email' => $email,
            'phone' => $phone,
            'intent' => Str::limit($a['intent'], 100, ''),
            'notes' => $a['notes'] ? Str::limit($a['notes'], 1000, '') : null,
            'consent_at' => now(),
            'retention_until' => now()->addDays(90),
            'status' => 'qualified',
        ]);
        $c->update(['outcome' => 'qualified_lead']);

        return ['ok' => true, 'lead_id' => $lead->id, 'status' => 'qualified'];
    }

    private function handoff(Conversation $c, array $a): array
    {
        $safe = PrivacyRedactor::structured($a);
        $c->update(['status' => 'human', 'priority' => $a['urgency'], 'handoff_reason' => PrivacyRedactor::text($a['reason']), 'handoff_summary' => PrivacyRedactor::text($a['summary']), 'suggested_reply' => PrivacyRedactor::text($a['suggested_reply']), 'outcome' => 'human_handoff', 'context' => array_merge($c->context ?? [], ['handoff' => $safe])]);

        return ['ok' => true, 'handoff' => true, 'message' => 'Human operator notified'];
    }

    private function reserve(Agent $agent, Conversation $c, array $a): array
    {
        return DB::transaction(function () use ($agent, $c, $a): array {
            $p = $agent->products()->where('is_active', true)->lockForUpdate()->find($a['product_id']);
            if (! $p) {
                return ['ok' => false, 'error' => 'Active product not found'];
            }
            Reservation::where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);
            $existing = Reservation::where('conversation_id', $c->id)->where('product_id', $p->id)->where('status', 'pending')->first();
            $heldByOthers = Reservation::where('product_id', $p->id)->where('status', 'pending')->where('expires_at', '>', now())->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->sum('quantity');
            $available = max(0, $p->stock - $heldByOthers);
            if ($available < $a['quantity']) {
                return ['ok' => false, 'error' => 'Insufficient available stock', 'product_id' => $p->id, 'available_stock' => $available];
            }
            $r = Reservation::updateOrCreate(
                ['conversation_id' => $c->id, 'product_id' => $p->id, 'status' => 'pending'],
                ['quantity' => $a['quantity'], 'expires_at' => now()->addMinutes(15)]
            );
            $c->update(['outcome' => 'pending_reservation', 'outcome_value' => (float) $p->price * $a['quantity']]);

            return ['ok' => true, 'reservation_id' => $r->id, 'product_id' => $p->id, 'quantity' => $r->quantity, 'expires_at' => $r->expires_at->toIso8601String(), 'requires_customer_confirmation' => true, 'source' => $this->catalogSource($agent)];
        });
    }

    private function offer(Agent $agent, Conversation $conversation, array $a): array
    {
        $requested = collect($a['items'] ?? [])->groupBy('product_id')->map(fn ($lines, $productId) => ['product_id' => (int) $productId, 'quantity' => (int) $lines->sum('quantity')])->values();
        if ($requested->isEmpty()) {
            return ['ok' => false, 'error' => 'At least one offer item is required.'];
        }
        foreach ($requested as $requestedItem) {
            $product = $agent->products()->where('is_active', true)->find($requestedItem['product_id']);
            if (! $product) {
                return ['ok' => false, 'error' => 'Product not found in this business catalog.'];
            }
            $available = $this->availableStock($product, $conversation);
            if ($available < $requestedItem['quantity']) {
                return ['ok' => false, 'error' => 'Requested quantity exceeds verified available stock.', 'product_id' => $product->id, 'requested' => $requestedItem['quantity'], 'available' => $available];
            }
        }
        $items = $requested->map(function ($i) use ($agent) {
            $p = $agent->products()->where('is_active', true)->find($i['product_id']);

            return $p ? ['product_id' => $p->id, 'name' => $p->name, 'quantity' => $i['quantity'], 'unit_price' => (float) $p->price, 'subtotal' => (float) $p->price * $i['quantity']] : null;
        })->filter()->values();

        $subtotal = (float) $items->sum('subtotal');
        $currency = strtoupper((string) ($agent->organization?->settings['currency'] ?? 'GEL'));
        $requestedDiscount = (float) ($a['discount_percent'] ?? 0);
        $allowedDiscount = (float) ($agent->settings['discount_limit'] ?? 0);
        if ($requestedDiscount > $allowedDiscount) {
            $conversation->update([
                'status' => 'human',
                'priority' => 'high',
                'handoff_reason' => "Requested {$requestedDiscount}% discount exceeds the {$allowedDiscount}% autonomous limit.",
                'handoff_summary' => 'Customer requested a discount that requires manager approval. Product quantities and verified subtotal are preserved in the trace.',
                'suggested_reply' => 'მადლობა დაინტერესებისთვის. ამ ფასდაკლებას მენეჯერის დადასტურება სჭირდება — მოთხოვნა უკვე გადავეცი და მალე დაგიბრუნდებით.',
                'outcome' => 'human_handoff',
            ]);

            return ['ok' => false, 'approval_required' => true, 'requested_discount_percent' => $requestedDiscount, 'allowed_discount_percent' => $allowedDiscount, 'subtotal' => $subtotal, 'total' => $subtotal, 'currency' => $currency, 'binding' => false];
        }
        $total = round($subtotal * (1 - $requestedDiscount / 100), 2);
        $conversation->update(['outcome' => 'offer_created', 'outcome_value' => $total]);

        return ['ok' => true, 'items' => $items, 'subtotal' => $subtotal, 'discount_percent' => $requestedDiscount, 'total' => $total, 'currency' => $currency, 'binding' => false, 'requires_customer_confirmation' => true, 'source' => $this->catalogSource($agent)];
    }

    private function consentMessage(Conversation $conversation, ?string $email, ?string $phone)
    {
        return $conversation->messages()->where('role', 'customer')->latest('id')->limit(4)->get()->first(function ($message) use ($email, $phone): bool {
            $text = Str::lower($message->content);
            if (preg_match('/(?:არ\s+(?:ვარ\s+)?თანახმა|do\s+not|don\'t|without\s+consent)/iu', $text)) {
                return false;
            }

            if (! preg_match('/(?:თანახმა|ვეთანხმები|ნებართვ|შეინახ(?:ეთ|ოთ|ე)|დამიკავშირდ|i\s+consent|you\s+may\s+(?:save|store)|save\s+my\s+contact|contact\s+me|call\s+me|email\s+me)/iu', $text)) {
                return false;
            }

            $evidence = array_merge_recursive(
                PrivacyRedactor::contactEvidence($message->content),
                is_array($message->metadata['contact_evidence'] ?? null) ? $message->metadata['contact_evidence'] : [],
            );

            return PrivacyRedactor::contactEvidenceMatches($evidence, $email, $phone);
        });
    }

    private function availableStock($product, ?Conversation $conversation = null): int
    {
        $held = Reservation::where('product_id', $product->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->when($conversation, fn ($query) => $query->where('conversation_id', '!=', $conversation->id))
            ->sum('quantity');

        return max(0, (int) $product->stock - (int) $held);
    }

    private function normalizedPhone(mixed $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 9 || strlen($digits) > 15) {
            return null;
        }

        return str_starts_with(trim($phone), '+') ? '+'.$digits : $digits;
    }

    private function addBusinessDays(CarbonImmutable $date, int $days): CarbonImmutable
    {
        $result = $date;
        $added = 0;
        while ($added < $days) {
            $result = $result->addDay();
            if ($result->isWeekday()) {
                $added++;
            }
        }

        return $result;
    }

    private function catalogSource(Agent $agent): array
    {
        return ['label' => 'Verified product catalog', 'type' => 'catalog', 'updated_at' => $agent->products()->max('updated_at')];
    }
}
