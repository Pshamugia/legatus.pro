<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Support\Str;

/**
 * Handles factual catalogue lookups before generative orchestration.
 *
 * A customer asking for an author, title, SKU, category, or other indexed
 * product term should never get a false "not found" merely because a model
 * chose the wrong tool arguments. Complex shopping advice still goes through
 * the OpenAI orchestrator; this path is deliberately narrow and deterministic.
 */
class VerifiedCatalogResponder
{
    public function __construct(private SalesToolbox $tools) {}

    public function respond(Agent $agent, Conversation $conversation, string $message): ?array
    {
        if ($this->isCartFollowUp($message) || $this->isExplicitContextFollowUp($message)) {
            return $this->respondFromRecentProducts($agent, $conversation, $message);
        }

        if (! $this->isCatalogLookup($message)) {
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

        $products = collect($search['products'] ?? [])->take(3)->values();
        $source = is_array($search['source'] ?? null) ? [$search['source']] : [];
        $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $message) === 1;

        if ($products->isEmpty()) {
            $suggestion = trim((string) ($search['did_you_mean'] ?? ''));
            if ($suggestion === '' && ! $this->hasExplicitLookupSignal($message)) {
                return null;
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
            ->filter(fn (array $check): bool => ($check['ok'] ?? false) === true)
            ->filter(fn (array $check): bool => (int) ($check['available_stock'] ?? $check['stock'] ?? 0) > 0);

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

        $ids = $verified->keys()->map(fn ($id): int => (int) $id)->values();
        $rank = $ids->flip();
        $models = $agent->products()
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

        return [
            'text' => $georgian
                ? "ვიპოვე {$models->count()} შესაბამისი ვარიანტი:\n{$lines->implode("\n")}\nრომელი გაინტერესებთ?"
                : "I found {$models->count()} matching option".($models->count() === 1 ? '' : 's').":\n{$lines->implode("\n")}\nWhich one interests you?",
            'intent' => $this->intent($message),
            'confidence' => .99,
            'handoff' => false,
            'escalation_reason' => null,
            'products' => $models,
            'sources' => $sources,
            'tools_used' => ['search_products', 'check_stock'],
        ];
    }

    private function respondFromRecentProducts(Agent $agent, Conversation $conversation, string $message): ?array
    {
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
        $contextual = $cart || $price || $sale || $stock || $details || $recommendation
            || Str::contains($text, ['ეს', 'ამის', 'მისი', 'იმის', 'პირველი', 'მეორე', 'მესამე', 'it', 'this', 'that', 'first', 'second', 'third']);
        if (! $contextual) {
            return null;
        }

        $selected = $this->selectRecentProducts($products, $text);
        if ($cart && $selected->count() !== 1) {
            return $this->clarifySelection($products, $georgian);
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

        $verifiedProducts = $selected->filter(fn ($product): bool => $checks->has($product->id))->values();
        $this->rememberProducts($conversation, $verifiedProducts->pluck('id')->all());
        $sources = $checks->pluck('source')->filter(fn ($source): bool => is_array($source))->values()->all();

        if ($cart) {
            $product = $verifiedProducts->first();
            $check = $checks->get($product->id, []);
            $available = (int) ($check['available_stock'] ?? $check['stock'] ?? 0) > 0;

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
        $models = $agent->products()->whereIn('id', $ids)->get()
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

        return [
            'text' => $georgian
                ? 'ამავე კონტექსტით ეს ვარიანტები შევარჩიე. რომელი გაინტერესებთ?'
                : 'Using the same context, I selected these alternatives. Which one interests you?',
            'intent' => 'recommendation', 'confidence' => .99, 'handoff' => false,
            'escalation_reason' => null, 'products' => $models,
            'sources' => [['label' => 'Verified product catalog', 'type' => 'catalog']],
            'tools_used' => ['remember_recent_products', 'recommend_products'],
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

        return $agent->products()->where('is_active', true)->whereIn('id', $ids)->get()
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

            return ($name !== '' && Str::contains($message, $name))
                || ($author !== '' && Str::contains($message, $author));
        })->values();

        return $named->isNotEmpty() ? $named : $products;
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
            'დამიმატ', 'კალათ', 'ვიყიდ', 'ყიდვა', 'შეძენა',
            'add it', 'add this', 'add to cart', 'buy it', 'purchase',
        ]);
    }

    private function isExplicitContextFollowUp(string $message): bool
    {
        $text = Str::lower($message);
        $reference = Str::contains($text, [
            'ეს', 'ამის', 'მისი', 'იმის', 'პირველი', 'მეორე', 'მესამე',
            ' it', 'this', 'that', 'first', 'second', 'third',
        ]);
        $question = Str::contains($text, [
            'ფასი', 'ღირს', 'sale', 'აქცია', 'ფასდაკლებ', 'ძველი ფასი',
            'მარაგ', 'ხელმისაწვდომ', 'დეტალ', 'ავტორ', 'ვინ დაწერა', 'რის შესახებ',
            'ლინკ', 'ბმულ', 'მაჩვენ', 'მირჩი', 'მსგავს', 'სხვა ვარიანტ',
            'price', 'cost', 'discount', 'stock', 'available', 'details', 'author',
            'about', 'link', 'show', 'recommend', 'similar', 'another option',
        ]);

        return $reference && $question;
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
        $availabilityOnly = data_get($product->metadata, 'stock_precision') === 'availability_only';

        return match (true) {
            $georgian && $availabilityOnly => "• {$identity} — {$sale} · ხელმისაწვდომია",
            $georgian => "• {$identity} — {$sale} · მარაგში {$stock} ც.",
            $availabilityOnly => "• {$identity} — {$sale} · available",
            default => "• {$identity} — {$sale} · {$stock} in stock",
        };
    }

    private function isCatalogLookup(string $message): bool
    {
        $text = Str::lower(trim($message));
        if ($text === '' || mb_strlen($text) > 300) {
            return false;
        }

        $blocked = [
            'ignore previous', 'system prompt', 'developer message', 'reveal your',
            'წინა ინსტრუქცი', 'სისტემური პრომპტ',
            'ოპერატორ', 'ადამიან', 'მენეჯერ', 'human', 'agent',
            'ფასდაკლებ', 'discount', 'საბითუმო', 'wholesale', 'bulk',
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

        return Str::contains($text, $lookupSignals) || $tokens->count() <= 5;
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
