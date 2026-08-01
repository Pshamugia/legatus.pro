<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Support\Str;

/**
 * Provides a narrow deterministic fallback when semantic orchestration is
 * explicitly unavailable.
 *
 * Production conversations use the OpenAI orchestrator with full conversation
 * history. This class must remain industry-neutral and is not an intent router.
 */
class VerifiedCatalogResponder
{
    public function __construct(private SalesToolbox $tools) {}

    public function respond(Agent $agent, Conversation $conversation, string $message): ?array
    {
        if ($this->isConversationRepair($message)) {
            $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $message) === 1;

            return [
                'text' => $georgian
                    ? 'ბოდიში — ჩემი წინა პასუხი გაუგებარი იყო. თავიდან, მარტივად დავიწყოთ: კონკრეტულ პროდუქტს ან მომსახურებას ეძებთ, თუ გსურთ თქვენი საჭიროებების მიხედვით რამდენიმე ვარიანტი გირჩიოთ?'
                    : 'Sorry — my previous reply was unclear. Let us start again simply: are you looking for a specific product or service, or would you like recommendations based on your needs?',
                'intent' => 'clarification',
                'confidence' => 1,
                'handoff' => false,
                'escalation_reason' => null,
                'products' => [],
                'sources' => [],
                'tools_used' => ['conversation_repair'],
            ];
        }

        // Service and policy questions must never inherit the last viewed
        // product. They belong to tenant knowledge and delivery tools, even
        // when the wording also contains a generic word such as "price".
        if ($this->isServiceOrPolicyIntent($message)) {
            return null;
        }

        // A short answer to the assistant's immediately preceding choice or
        // clarification question is not an independent catalog query. Let the
        // semantic orchestrator resolve the omitted subject from conversation
        // history (for example: "classic" after "classic or modern?").
        if ($this->isAnswerToRecentQuestion($conversation, $message)) {
            return null;
        }

        if ($this->isRelatedEntityFollowUp($conversation, $message)) {
            return null;
        }

        // A customer challenging the availability shown in the previous
        // answer is asking us to re-check that same item, not to run a broad
        // catalog search or suggest substitutes.
        if ($this->isAvailabilityCorrectionFollowUp($conversation, $message)) {
            return $this->respondFromRecentProducts($agent, $conversation, $message, true);
        }

        $contextOnlyReference = $this->isContextOnlyProductReference($message);
        if ($contextOnlyReference && $this->recentProducts($agent, $conversation)->isEmpty()) {
            $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $message) === 1;

            return [
                'text' => $georgian
                    ? 'ზუსტად ვერ გავიგე, რომელ პროდუქტს ან მომსახურებას გულისხმობთ. მომწერეთ სახელი ან ერთი განმასხვავებელი მახასიათებელი და სწორ ვარიანტზე მოგიყვებით.'
                    : 'I am not sure which product or service you mean. Send its name or one distinguishing detail and I will tell you about the correct option.',
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'products' => [],
                'sources' => [],
                'tools_used' => ['clarify_product_reference'],
            ];
        }

        if ($contextOnlyReference) {
            return $this->respondFromRecentProducts($agent, $conversation, $message);
        }

        if ($this->isCartFollowUp($message)) {
            return $this->respondFromRecentProducts($agent, $conversation, $message);
        }

        if (! $this->isCatalogLookup($agent, $message)) {
            return $this->respondFromRecentProducts($agent, $conversation, $message);
        }

        $search = $this->tools->execute('search_products', [
            'query' => $message,
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        if (($search['ok'] ?? false) !== true) {
            return null;
        }

        $products = collect($search['products'] ?? [])
            ->merge($search['unavailable_products'] ?? [])
            ->unique('id')
            ->take(6)
            ->values();
        $source = is_array($search['source'] ?? null) ? [$search['source']] : [];
        $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $message) === 1;

        if ($products->isEmpty()) {
            $suggestion = trim((string) ($search['did_you_mean'] ?? ''));
            if ($suggestion === '' && ! $this->hasExplicitLookupSignal($message)) {
                return null;
            }
            if ($suggestion === '') {
                return [
                    'text' => $georgian
                        ? 'ზუსტი დამთხვევა ვერ ვიპოვე. მომწერეთ პროდუქტის სახელი, ბრენდი, კატეგორია ან თქვენთვის მნიშვნელოვანი მახასიათებელი.'
                        : 'I could not find an exact match. Send the product name, brand, category, or another feature that matters to you.',
                    'intent' => 'discovery',
                    'confidence' => .99,
                    'handoff' => false,
                    'escalation_reason' => null,
                    'products' => [],
                    'sources' => $source,
                    'tools_used' => ['search_products'],
                ];
            }

            return [
                'text' => $suggestion !== ''
                    ? ($georgian
                        ? "ამას ხომ არ გულისხმობდით: {$suggestion}? დამიდასტურეთ და ზუსტად ამ სახელით მოვძებნი."
                        : "Did you mean {$suggestion}? Confirm the spelling and I will search for that exact name.")
                    : ($georgian
                        ? 'ამ ფორმულირებით ზუსტი დამთხვევა ვერ ვიპოვე. სცადეთ ავტორის, სათაურის, ჟანრის, ISBN-ის ან სხვა საკვანძო სიტყვის მითითება.'
                        : 'I could not find an exact match for that wording. Try an author, title, genre, ISBN, or another product keyword.'),
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'products' => [],
                'sources' => $source,
                'tools_used' => ['search_products'],
            ];
        }

        $checks = $products->mapWithKeys(function (array $product) use ($agent, $conversation): array {
            $id = (int) ($product['id'] ?? 0);
            if ($id < 1) {
                return [];
            }

            return [$id => $this->tools->execute('check_stock', [
                'product_id' => $id,
                'quantity' => 1,
            ], $agent, $conversation)];
        });
        $verified = $checks
            ->filter(fn (array $check): bool => ($check['ok'] ?? false) === true);

        if ($verified->isEmpty()) {
            return [
                'text' => $georgian
                    ? 'შესაბამისი პროდუქტები ვიპოვე, მაგრამ მათი მიმდინარე ფასი და ხელმისაწვდომობა ახლა ვერ გადავამოწმე. შეგიძლიათ ცოტა ხანში ხელახლა სცადოთ.'
                    : 'I found matching products, but I could not verify their current price and availability right now. Please try again shortly.',
                'intent' => 'discovery',
                'confidence' => .95,
                'handoff' => false,
                'escalation_reason' => null,
                'products' => [],
                'sources' => $source,
                'tools_used' => ['search_products', 'check_stock'],
            ];
        }

        $availableVerified = $verified->filter(
            fn (array $check): bool => $this->checkIsAvailable($check),
        );
        if ($availableVerified->isNotEmpty()) {
            // When an available match exists, do not distract the customer
            // with duplicate sold-out listings of the same requested item.
            // Sold-out products remain useful only when no available match
            // was found at all.
            $verified = $availableVerified;
        }

        $ids = $verified->keys()->map(fn ($id): int => (int) $id)->values();
        $rank = $ids->flip();
        $models = $agent->customerProducts()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn ($product): int => (int) $rank->get($product->id, PHP_INT_MAX))
            ->values();
        $this->rememberProducts($conversation, $models->pluck('id')->all());
        $lines = $models->map(fn ($product): string => $this->productLine(
            $product,
            $verified->get($product->id, []),
            $georgian,
        ));
        $sources = collect($source)
            ->merge($verified->pluck('source')->filter(fn ($item): bool => is_array($item)))
            ->unique(fn (array $item): string => implode('|', [
                (string) ($item['label'] ?? ''),
                (string) ($item['type'] ?? ''),
            ]))
            ->values()
            ->all();

        if ($verified->every(fn (array $check): bool => ! $this->checkIsAvailable($check))) {
            $alternativeReply = $this->soldOutAlternatives(
                $agent,
                $conversation,
                $models->first(),
                $lines->first(),
                $georgian,
                $sources,
            );
            if ($alternativeReply !== null) {
                return $alternativeReply;
            }
        }

        $nextStep = $models->count() === 1
            ? ($georgian ? 'გაინტერესებთ შეძენა?' : 'Would you like to purchase it?')
            : ($georgian ? 'რომელი გაინტერესებთ?' : 'Which one interests you?');

        return [
            'text' => $georgian
                ? "ვიპოვე {$models->count()} შესაბამისი ვარიანტი:\n{$lines->implode("\n")}\n{$nextStep}"
                : "I found {$models->count()} matching option".($models->count() === 1 ? '' : 's').":\n{$lines->implode("\n")}\n{$nextStep}",
            'intent' => $this->intent($message),
            'confidence' => .99,
            'handoff' => false,
            'escalation_reason' => null,
            'products' => $models,
            'sources' => $sources,
            'tools_used' => ['search_products', 'check_stock'],
        ];
    }

    private function respondFromRecentProducts(
        Agent $agent,
        Conversation $conversation,
        string $message,
        bool $onlyReferencedProduct = false,
    ): ?array {
        $products = $this->recentProducts($agent, $conversation);
        if ($products->isEmpty()) {
            return null;
        }

        $text = Str::lower(trim($message));
        $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $message) === 1;
        $cart = $this->isCartFollowUp($message);
        $price = Str::contains($text, ['ფასი', 'ღირს', 'რა ღირს', 'price', 'cost']);
        $sale = Str::contains($text, ['sale', 'აქცია', 'ფასდაკლებ', 'ძველი ფასი', 'discount', 'original price']);
        $stock = Str::contains($text, ['მარაგ', 'ხელმისაწვდომ', 'გაქვთ', 'გაქვს', 'stock', 'available']);
        $details = Str::contains($text, ['დეტალ', 'ავტორ', 'ვინ დაწერა', 'რის შესახებ', 'აღწერ', 'ლინკ', 'ბმულ', 'მაჩვენ', 'details', 'author', 'about', 'link', 'show']);
        $recommendation = Str::contains($text, ['მირჩი', 'მსგავს', 'სხვა ვარიანტ', 'recommend', 'similar', 'another option']);
        $contextual = $this->isContextOnlyProductReference($message)
            || $cart || $price || $sale || $stock || $details || $recommendation
            || Str::contains($text, ['ეს', 'ამის', 'მისი', 'იმის', 'პირველი', 'მეორე', 'მესამე', 'it', 'this', 'that', 'first', 'second', 'third']);
        if (! $contextual) {
            return null;
        }

        $selected = $this->selectRecentProducts($products, $text);
        if ($onlyReferencedProduct && $selected->count() > 1) {
            $selected = $selected->take(1)->values();
        }
        if ($cart && $selected->count() !== 1) {
            return $this->clarifySelection($products, $georgian);
        }

        if ($recommendation && ! $this->referencesRecentProduct($text, $products)) {
            // A new shopping request such as "recommend philosophical books"
            // is not a follow-up merely because the conversation has history.
            // Let the agent reason over the new constraints and live catalog.
            return null;
        }

        if ($recommendation) {
            return $this->recommendFromRecentProduct($agent, $conversation, $selected->first() ?? $products->first(), $message, $georgian);
        }

        $checks = $selected->mapWithKeys(fn ($product): array => [
            $product->id => $this->tools->execute('check_stock', [
                'product_id' => $product->id,
                'quantity' => 1,
            ], $agent, $conversation),
        ])->filter(fn (array $check): bool => ($check['ok'] ?? false) === true);
        if ($checks->isEmpty()) {
            return null;
        }

        $availableChecks = $checks->filter(
            fn (array $check): bool => $this->checkIsAvailable($check),
        );
        if ($availableChecks->isNotEmpty()) {
            $checks = $availableChecks;
        }

        $verifiedProducts = $selected->filter(fn ($product): bool => $checks->has($product->id))->values();
        $this->rememberProducts($conversation, $verifiedProducts->pluck('id')->all());
        $sources = $checks->pluck('source')->filter(fn ($source): bool => is_array($source))->values()->all();

        if ($cart) {
            $product = $verifiedProducts->first();
            $check = $checks->get($product->id, []);
            $available = $this->checkIsAvailable($check);

            return [
                'text' => $available
                    ? ($georgian
                        ? "კი — {$product->name} ხელმისაწვდომია. პროდუქტის ბარათზე დაჭერით გახსნით მის გვერდს და უსაფრთხოდ დაამატებთ კალათაში."
                        : "Yes — {$product->name} is available. Open its product card to add it to the store cart securely.")
                    : ($georgian ? "{$product->name} ამჟამად ხელმისაწვდომი აღარ არის." : "{$product->name} is currently unavailable."),
                'intent' => 'stock',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'products' => $verifiedProducts,
                'sources' => $sources,
                'tools_used' => ['remember_recent_products', 'check_stock'],
            ];
        }

        $lines = $verifiedProducts->map(fn ($product): string => $this->productLine(
            $product,
            $checks->get($product->id, []),
            $georgian,
        ));

        return [
            'text' => $georgian
                ? "წინა პასუხში ნაჩვენები პროდუქტის გადამოწმებული ინფორმაციაა:\n{$lines->implode("\n")}"
                : "Here is the verified information for the product from my previous answer:\n{$lines->implode("\n")}",
            'intent' => $price || $sale ? 'price' : ($stock ? 'stock' : 'discovery'),
            'confidence' => .99,
            'handoff' => false,
            'escalation_reason' => null,
            'products' => $verifiedProducts,
            'sources' => $sources,
            'tools_used' => ['remember_recent_products', 'check_stock'],
        ];
    }

    private function recommendFromRecentProduct(Agent $agent, Conversation $conversation, $seed, string $message, bool $georgian): array
    {
        $author = trim((string) data_get($seed->metadata, 'author', ''));
        $query = trim(implode(' ', array_filter([$message, $seed->category, $author, $seed->description])));
        $result = $this->tools->execute('recommend_products', [
            'query' => Str::limit($query, 500, ''),
            'budget' => null,
            'category' => $seed->category,
            'mood' => null,
            'occasion' => null,
            'limit' => 5,
        ], $agent, $conversation);
        $ids = collect($result['recommendations'] ?? [])
            ->pluck('id')->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $seed->id)
            ->take(3)->values();
        $models = $agent->customerProducts()->whereIn('id', $ids)->get()
            ->sortBy(fn ($product): int => $ids->search((int) $product->id))->values();
        if (($result['ok'] ?? false) !== true || $models->isEmpty()) {
            return [
                'text' => $georgian
                    ? 'მსგავსი ვარიანტის ზუსტად შესარჩევად მითხარით, უფრო ავტორი, ჟანრი თუ თემაა თქვენთვის მნიშვნელოვანი?'
                    : 'To choose a genuinely similar option, which matters more: the author, genre, or subject?',
                'intent' => 'recommendation', 'confidence' => .99, 'handoff' => false,
                'escalation_reason' => null, 'products' => [], 'sources' => [],
                'tools_used' => ['recommend_products'],
            ];
        }

        $this->rememberProducts($conversation, $models->pluck('id')->all());

        $nextStep = $models->count() === 1
            ? ($georgian ? 'გაინტერესებთ ამ პროდუქტის შეძენა?' : 'Would you like to purchase this product?')
            : ($georgian ? 'რომელი გაინტერესებთ?' : 'Which one interests you?');

        return [
            'text' => $georgian
                ? "ამავე კონტექსტით შესაბამისი ვარიანტი შევარჩიე. {$nextStep}"
                : "Using the same context, I selected the matching option. {$nextStep}",
            'intent' => 'recommendation', 'confidence' => .99, 'handoff' => false,
            'escalation_reason' => null, 'products' => $models,
            'sources' => [['label' => 'Verified product catalog', 'type' => 'catalog']],
            'tools_used' => ['remember_recent_products', 'recommend_products'],
        ];
    }

    private function soldOutAlternatives(
        Agent $agent,
        Conversation $conversation,
        $seed,
        ?string $soldOutLine,
        bool $georgian,
        array $sources,
    ): ?array {
        if (! $seed || ! is_string($soldOutLine)) {
            return null;
        }

        $taxonomy = collect((array) data_get($seed->metadata, 'genres', []))
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->values();
        $category = trim((string) $seed->category);
        $query = $taxonomy->isNotEmpty() ? $taxonomy->implode(' ') : $category;
        if ($query === '') {
            return null;
        }

        $recommendation = $this->tools->execute('recommend_products', [
            'query' => Str::limit($query, 300, ''),
            'budget' => null,
            'category' => $category !== '' ? $category : null,
            'mood' => null,
            'occasion' => null,
            'limit' => 5,
        ], $agent, $conversation);
        if (($recommendation['ok'] ?? false) !== true) {
            return null;
        }

        $ids = collect($recommendation['recommendations'] ?? [])
            ->pluck('id')->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $seed->id)
            ->take(3)->values();
        $alternatives = $agent->customerProducts()->whereIn('id', $ids)->get()
            ->sortBy(fn ($product): int => (int) $ids->search((int) $product->id))->values();
        $checks = $alternatives->mapWithKeys(fn ($product): array => [
            $product->id => $this->tools->execute('check_stock', [
                'product_id' => $product->id,
                'quantity' => 1,
            ], $agent, $conversation),
        ])->filter(fn (array $check): bool => ($check['ok'] ?? false) === true && $this->checkIsAvailable($check));
        $alternatives = $alternatives->filter(fn ($product): bool => $checks->has($product->id))->values();
        if ($alternatives->isEmpty()) {
            return null;
        }

        $alternativeLines = $alternatives->map(fn ($product): string => $this->productLine(
            $product,
            $checks->get($product->id, []),
            $georgian,
        ));
        $this->rememberProducts($conversation, $alternatives->pluck('id')->all());

        return [
            'text' => $georgian
                ? "{$soldOutLine}\nიმავე კატეგორიიდან შემიძლია შემოგთავაზოთ:\n{$alternativeLines->implode("\n")}"
                : "{$soldOutLine}\nFrom the same category, I can offer:\n{$alternativeLines->implode("\n")}",
            'intent' => 'recommendation',
            'confidence' => .99,
            'handoff' => false,
            'escalation_reason' => null,
            'products' => $alternatives,
            'sources' => collect($sources)
                ->merge($checks->pluck('source')->filter(fn ($item): bool => is_array($item)))
                ->values()->all(),
            'tools_used' => ['search_products', 'check_stock', 'recommend_products'],
        ];
    }

    private function recentProducts(Agent $agent, Conversation $conversation)
    {
        $ids = collect(data_get($conversation->context, 'last_catalog_product_ids', []))
            ->map(fn ($id): int => (int) $id)->filter()->unique()->take(3)->values();
        if ($ids->isEmpty()) {
            $latest = $conversation->messages()->where('role', 'assistant')->latest('id')->first();
            $ids = collect(data_get($latest?->metadata, 'products', []))
                ->pluck('id')->map(fn ($id): int => (int) $id)->filter()->unique()->take(3)->values();
        }

        return $agent->customerProducts()->where('is_active', true)->whereIn('id', $ids)->get()
            ->sortBy(fn ($product): int => $ids->search((int) $product->id))->values();
    }

    private function selectRecentProducts($products, string $message)
    {
        $ordinal = match (true) {
            Str::contains($message, ['პირველ', 'first']) => 0,
            Str::contains($message, ['მეორე', 'second']) => 1,
            Str::contains($message, ['მესამე', 'third']) => 2,
            default => null,
        };
        if ($ordinal !== null && $products->has($ordinal)) {
            return collect([$products->get($ordinal)]);
        }

        $named = $products->filter(function ($product) use ($message): bool {
            $name = Str::lower((string) $product->name);
            $author = Str::lower((string) data_get($product->metadata, 'author', ''));

            $nameTokenMatch = collect(preg_split('/[^\pL\pN]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
                ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
                ->contains(fn (string $token): bool => Str::contains($message, $token));

            return ($name !== '' && Str::contains($message, $name))
                || ($author !== '' && Str::contains($message, $author))
                || $nameTokenMatch;
        })->values();

        return $named->isNotEmpty() ? $named : $products;
    }

    private function referencesRecentProduct(string $message, $products): bool
    {
        if (Str::contains($message, [
            'ეს', 'ამის', 'მისი', 'იმის', 'პირველ', 'მეორე', 'მესამე',
            ' it', 'this', 'that', 'first', 'second', 'third',
        ])) {
            return true;
        }

        return $products->contains(function ($product) use ($message): bool {
            $name = Str::lower((string) $product->name);
            $author = Str::lower((string) data_get($product->metadata, 'author', ''));

            return ($name !== '' && Str::contains($message, $name))
                || ($author !== '' && Str::contains($message, $author));
        });
    }

    private function clarifySelection($products, bool $georgian): array
    {
        $names = $products->values()->map(fn ($product, int $index): string => ($index + 1).'. '.$product->name)->implode("\n");

        return [
            'text' => $georgian
                ? "რომელი პროდუქტის დამატება გსურთ? მომწერეთ ნომერი ან სათაური:\n{$names}"
                : "Which product would you like to add? Send its number or title:\n{$names}",
            'intent' => 'discovery', 'confidence' => .99, 'handoff' => false,
            'escalation_reason' => null, 'products' => $products,
            'sources' => [], 'tools_used' => ['remember_recent_products'],
        ];
    }

    private function rememberProducts(Conversation $conversation, array $ids): void
    {
        $context = is_array($conversation->context) ? $conversation->context : [];
        $context['last_catalog_product_ids'] = collect($ids)->map(fn ($id): int => (int) $id)->filter()->unique()->take(3)->values()->all();
        $conversation->update(['context' => $context]);
    }

    private function isCartFollowUp(string $message): bool
    {
        return Str::contains(Str::lower($message), [
            'დამიმატ', 'კალათ', 'ვიყიდ', 'ვიყიდო', 'ყიდვა', 'შეძენა', 'შეიძ', 'შევიძ',
            'add it', 'add this', 'add to cart', 'buy it', 'how to buy', 'purchase',
        ]);
    }

    private function isContextOnlyProductReference(string $message): bool
    {
        $text = Str::lower(trim($message));

        if (preg_match('/\b(?:this|that)\s+(?:book|product|title)\b/iu', $text)) {
            return true;
        }
        if (! preg_match('/(?:^|\s)(?:ამ|ამავე|ეს|იმ|მაგ|მასზე)(?:\s|$)/u', $text)
            && ! preg_match('/(?:ამ|ამავე|ეს|იმ|მაგ)\s*(?:წიგნ|ნაწარმოებ|პროდუქტ)/u', $text)) {
            return false;
        }

        $remainder = preg_replace(
            '/(?:ამ|ამავე|ეს|იმ|მაგ|მასზე)\s*(?:წიგნ(?:ზე|ის|ს|ი)?|ნაწარმოებ(?:ზე|ის|ს|ი)?|პროდუქტ(?:ზე|ის|ს|ი)?)?/u',
            ' ',
            $text,
        );
        $remainder = preg_replace(
            '/\b(?:რას|რა|მეტყვი|იტყვი|ფიქრობ|მითხარი|მომიყევი|შეგიძლია|შეგიძლიათ|ავტორი|ვინ|არის|იყო|შესახებ|უფრო|დეტალურად|ინფორმაცია)\b/u',
            ' ',
            (string) $remainder,
        );

        return trim((string) preg_replace('/[^\pL\pN]+/u', '', (string) $remainder)) === '';
    }

    private function productLine($product, array $check, bool $georgian): string
    {
        $author = trim((string) data_get($product->metadata, 'author', ''));
        $identity = $author !== '' ? "{$product->name} — {$author}" : $product->name;
        $currentPrice = (float) ($check['price'] ?? $product->price);
        $price = $this->money($currentPrice);
        $original = (float) data_get($product->metadata, 'original_price', 0);
        $sale = $original > $currentPrice
            ? ($georgian
                ? $this->money($original)." ₾-ის ნაცვლად {$price} ₾ (".round((1 - $currentPrice / $original) * 100).'% ფასდაკლება)'
                : $price.' GEL, reduced from '.$this->money($original).' GEL ('.round((1 - $currentPrice / $original) * 100).'% off)')
            : ($georgian ? "{$price} ₾" : "{$price} GEL");
        $stock = (int) ($check['available_stock'] ?? $check['stock'] ?? 0);
        $availabilityOnly = ($check['stock_precision'] ?? data_get($product->metadata, 'stock_precision')) === 'availability_only';
        $available = $this->checkIsAvailable($check);

        return match (true) {
            $georgian && ! $available => "• {$identity} — {$sale} · ამჟამად მარაგში არ არის",
            ! $available => "• {$identity} — {$sale} · currently out of stock",
            $georgian && $availabilityOnly => "• {$identity} — {$sale} · მარაგშია",
            $georgian => "• {$identity} — {$sale} · მარაგში {$stock} ც.",
            $availabilityOnly => "• {$identity} — {$sale} · available",
            default => "• {$identity} — {$sale} · {$stock} in stock",
        };
    }

    private function checkIsAvailable(array $check): bool
    {
        if (is_bool($check['available'] ?? null)) {
            return $check['available'];
        }

        return (int) ($check['available_stock'] ?? $check['stock'] ?? 0) > 0;
    }

    private function isCatalogLookup(Agent $agent, string $message): bool
    {
        $text = Str::lower(trim($message));
        if ($text === '' || mb_strlen($text) > 300) {
            return false;
        }

        $blocked = [
            'ignore previous', 'system prompt', 'developer message', 'reveal your',
            'წინა ინსტრუქცი', 'სისტემური პრომპტ',
            'ოპერატორ', 'ადამიან', 'მენეჯერ', 'human', 'agent',
            'ფასდაკლებ', 'discount', 'sale', 'აქცია', 'ძველი ფასი', 'original price',
            'საბითუმო', 'wholesale', 'bulk',
            'მიწოდებ', 'ჩამომივა', 'delivery', 'shipping',
            'დაბრუნებ', 'გარანტი', 'policy', 'return policy', 'refund',
            'მირჩი', 'მსგავს', 'შეადარ', 'აირჩი', 'გადაწყვიტ', 'სხვა ვარიანტ',
            'recommend', 'similar', 'compare', 'choose', 'decide', 'another option',
            'შეკვეთ', 'გადახდ', 'checkout', 'payment', 'order',
            'დაჯავშნ', 'შემინახ', 'reserve', 'reservation', 'hold it',
        ];
        if (Str::contains($text, $blocked)) {
            return false;
        }

        $social = [
            'გამარჯობა', 'გაგიმარჯოს', 'მადლობა', 'კარგი', 'დიახ', 'არა', 'ნახვამდის',
            'hello', 'hi', 'hey', 'thanks', 'thank you', 'yes', 'no', 'okay', 'ok', 'bye',
        ];
        if (in_array($text, $social, true)) {
            return false;
        }

        $lookupSignals = [
            'გაქვთ', 'გაქვს', 'მაჩვენ', 'მომიძებნ', 'ვეძებ', 'რა გაქვთ', 'ფასი', 'ღირს',
            'მარაგ', 'ხელმისაწვდომ', 'წიგნ', 'ავტორ', 'სათაურ', 'isbn',
            'do you have', 'have any', 'find', 'show', 'looking for', 'price', 'cost',
            'stock', 'available', 'book', 'author', 'title', 'sku',
        ];
        $tokens = collect(preg_split('/[^\pL\pN]+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
        $genericTokens = [
            'a', 'an', 'any', 'are', 'available', 'about', 'book', 'books', 'can', 'cost',
            'do', 'does', 'find', 'for', 'have', 'how', 'i', 'is', 'it', 'looking', 'many',
            'me', 'of', 'please', 'price', 'show', 'stock', 'tell', 'the', 'this', 'to',
            'want', 'what', 'which', 'you',
            'ან', 'არის', 'გაქვთ', 'გაქვს', 'და', 'თუ', 'მაჩვენე', 'მაჩვენეთ', 'მინდა', 'ეს', 'ამ', 'ამის', 'მისი', 'იმის',
            'მომიძებნე', 'რა', 'რამდენი', 'რომელი', 'შეგიძლიათ', 'შეიძლება', 'თქვენ', 'ხომ',
            'ფასი', 'ღირს', 'მარაგი', 'ხელმისაწვდომია', 'წიგნი', 'წიგნები', 'წიგნის',
            'მარაგშია', 'მარაგში', 'ხელმისაწვდომი',
            'პროდუქტი', 'პროდუქტები', 'ნამუშევარი', 'ნამუშევრები', 'გამოცემა', 'გამოცემები',
        ];
        $identityTokens = $tokens
            ->reject(fn (string $token): bool => in_array($token, $genericTokens, true))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 2);

        // Never answer a context-only question ("What is the price?", "How
        // many are available?") as if it identified a product. Those messages
        // require the full contextual and factual-claim guardrails.
        if ($identityTokens->isEmpty()) {
            return false;
        }

        if (Str::contains($text, $lookupSignals)) {
            return true;
        }

        // Short text is not automatically a product search. Without a lookup
        // verb, require a real identity already present in this tenant catalog.
        $identity = $identityTokens->sortByDesc(fn (string $token): int => mb_strlen($token))->first();
        if (! is_string($identity) || mb_strlen($identity) < 3) {
            return false;
        }
        $literal = addcslashes($identity, '\\%_');

        return $agent->customerProducts()
            ->where(function ($query) use ($literal): void {
                $query->where('search_text', 'like', "%{$literal}%")
                    ->orWhere('name', 'like', "%{$literal}%")
                    ->orWhere('sku', 'like', "%{$literal}%");
            })
            ->exists();
    }

    private function isAnswerToRecentQuestion(Conversation $conversation, string $message): bool
    {
        $text = trim($message);
        if ($text === '' || mb_strlen($text) > 100 || str_contains($text, '?')) {
            return false;
        }

        $tokens = preg_split('/[^\pL\pN]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $contextualShortAnswer = preg_match(
            '/^(?:კი|არა|დიახ|კლასიკური|ამ\s+ავტორის|ეს\s+(?:წიგნი|პროდუქტი)|yes|no|classic|this\s+(?:book|product)|by\s+this\s+author)$/iu',
            Str::lower($text),
        ) === 1;
        if (count($tokens) > 8 || (! $contextualShortAnswer && $this->hasExplicitLookupSignal($message))) {
            return false;
        }

        $previous = $conversation->messages()
            ->where('role', 'assistant')
            ->latest('id')
            ->first();
        if (! $previous) {
            return false;
        }

        $question = Str::lower(trim((string) $previous->content));
        $askedForChoice = str_contains($question, '?')
            && (
                str_contains($question, ' თუ ')
                || preg_match('/\bor\b/iu', $question) === 1
                || str_contains($question, '/')
                || preg_match('/(?:რომელ|რომელი|რომელს|რომლით|which|prefer|choose)/iu', $question) === 1
            );

        return $askedForChoice;
    }

    private function isRelatedEntityFollowUp(Conversation $conversation, string $message): bool
    {
        if ($this->recentProducts($conversation->agent, $conversation)->isEmpty()) {
            return false;
        }

        $text = Str::lower(trim($message));
        if ($text === '' || mb_strlen($text) > 200) {
            return false;
        }

        $asksForMore = preg_match(
            '/(?:\b(?:another|other|more|else)\b|(?:სხვა|კიდევ|დამატებით))/iu',
            $text,
        ) === 1;
        $refersBack = preg_match(
            '/(?:\b(?:this|same|that|its)\b|(?:ამ|იგივე|მისი|იმავე))/iu',
            $text,
        ) === 1;

        return $asksForMore && $refersBack;
    }

    private function isAvailabilityCorrectionFollowUp(Conversation $conversation, string $message): bool
    {
        if ($this->recentProducts($conversation->agent, $conversation)->isEmpty()) {
            return false;
        }

        $text = Str::lower(trim($message));
        $challengesPreviousAnswer = preg_match('/(?:კი\s*მაგრამ|მაგრამ|but|however|წერია\s+რომ)/iu', $text) === 1;
        $mentionsUnavailable = preg_match('/(?:ამოწურულ|ამოიწურა|მარაგში\s+არ\s+არის|sold\s*out|out\s+of\s+stock|unavailable)/iu', $text) === 1;

        return $challengesPreviousAnswer && $mentionsUnavailable;
    }

    private function isConversationRepair(string $message): bool
    {
        $text = Str::lower(trim($message));

        return Str::contains($text, [
            'ვერ გავიგე',
            'ვერ მივხვდი',
            'რას მწერ',
            'რას მპასუხობ',
            'რას ნიშნავს',
            'გაუგებარია',
            'არასწორი ლინკ',
            'ლინკი არასწორია',
            'ბმული არასწორია',
            'თავიდან ამიხსენი',
            'უფრო მარტივად',
            'i do not understand',
            "i don't understand",
            'what do you mean',
            'that was unclear',
            'wrong link',
        ]);
    }

    private function isServiceOrPolicyIntent(string $message): bool
    {
        return (bool) preg_match(
            '/(?:მიწოდ|ჩამომივ|მიტან|კურიერ|ტრანსპორტირ|delivery|shipping|courier|დაბრუნ|refund|return\s+policy|გარანტი|warranty|გადახდ|payment)/iu',
            $message,
        );
    }

    private function hasExplicitLookupSignal(string $message): bool
    {
        return Str::contains(Str::lower($message), [
            'გაქვთ', 'გაქვს', 'მაჩვენ', 'მომიძებნ', 'ვეძებ', 'ფასი', 'ღირს',
            'მარაგ', 'ხელმისაწვდომ', 'წიგნ', 'ავტორ', 'სათაურ', 'isbn',
            'do you have', 'have any', 'find', 'show', 'looking for', 'price', 'cost',
            'stock', 'available', 'book', 'author', 'title', 'sku',
        ]);
    }

    private function intent(string $message): string
    {
        $text = Str::lower($message);
        if (Str::contains($text, ['ფასი', 'ღირს', 'price', 'cost'])) {
            return 'price';
        }
        if (Str::contains($text, ['მარაგ', 'ხელმისაწვდომ', 'გაქვთ', 'გაქვს', 'stock', 'available', 'do you have'])) {
            return 'stock';
        }

        return 'discovery';
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
