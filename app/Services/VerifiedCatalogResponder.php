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
        if (! $this->isCatalogLookup($message)) {
            return null;
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
        $lines = $models->map(function ($product) use ($verified, $georgian): string {
            $check = $verified->get($product->id, []);
            $author = trim((string) data_get($product->metadata, 'author', ''));
            $identity = $author !== '' ? "{$product->name} — {$author}" : $product->name;
            $price = $this->money((float) ($check['price'] ?? $product->price));
            $stock = (int) ($check['available_stock'] ?? $check['stock'] ?? 0);
            $availabilityOnly = data_get($product->metadata, 'stock_precision') === 'availability_only';

            return match (true) {
                $georgian && $availabilityOnly => "• {$identity} — {$price} ₾ · ხელმისაწვდომია",
                $georgian => "• {$identity} — {$price} ₾ · მარაგში {$stock} ც.",
                $availabilityOnly => "• {$identity} — {$price} GEL · available",
                default => "• {$identity} — {$price} GEL · {$stock} in stock",
            };
        });
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
            'ან', 'არის', 'გაქვთ', 'გაქვს', 'და', 'თუ', 'მაჩვენე', 'მაჩვენეთ', 'მინდა',
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
