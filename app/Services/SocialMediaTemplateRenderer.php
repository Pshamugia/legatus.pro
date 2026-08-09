<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialMediaTemplateRenderer
{
    public const ALLOWED_TOKENS = [
        'business_name',
        'product_title',
        'product_description',
        'price',
        'category',
        'delivery',
        'product_url',
    ];

    public const CHARACTER_LIMITS = [
        'facebook' => 6000,
        'instagram' => 2200,
    ];

    /** @param array{body_template?: mixed, delivery_enabled?: mixed, delivery_text?: mixed} $config */
    public function validate(string $provider, array $config, string $fieldPrefix): void
    {
        $body = $this->cleanText((string) ($config['body_template'] ?? ''));
        $errors = [];
        $rawLimit = $provider === 'instagram' ? 1800 : 5000;

        if ($body === '') {
            $errors[$fieldPrefix.'.body_template'] = 'The post template cannot be empty.';
        } elseif (mb_strlen($body) > $rawLimit) {
            $errors[$fieldPrefix.'.body_template'] = ucfirst($provider)." template may not exceed {$rawLimit} characters.";
        }

        preg_match_all('/\{([a-z_]+)\}/iu', $body, $matches);
        $unknown = collect($matches[1] ?? [])->map(fn ($token) => mb_strtolower((string) $token))
            ->diff(self::ALLOWED_TOKENS)->unique()->values();
        if ($unknown->isNotEmpty()) {
            $errors[$fieldPrefix.'.body_template'] = 'Unknown template field: '.$unknown->map(fn ($token) => '{'.$token.'}')->implode(', ').'.';
        }
        foreach (['product_title', 'product_url'] as $required) {
            if (! Str::contains($body, '{'.$required.'}')) {
                $errors[$fieldPrefix.'.body_template'] = 'Keep both {product_title} and {product_url} in every template.';
                break;
            }
        }

        if (mb_strlen($this->cleanText((string) ($config['delivery_text'] ?? ''))) > 600) {
            $errors[$fieldPrefix.'.delivery_text'] = 'Delivery details may not exceed 600 characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array{body_template: string, delivery_enabled: bool, delivery_text: ?string}  $config
     */
    public function render(string $provider, array $config, Agent $agent, Product $product): string
    {
        $this->validate($provider, $config, 'templates.'.$provider);
        $url = trim((string) data_get($product->metadata, 'product_url'));
        if (! $this->publicHttpUrl($url)) {
            throw ValidationException::withMessages([
                'categories' => "{$product->name} does not have a usable public product URL.",
            ]);
        }

        $body = $this->cleanText($config['body_template']);
        $delivery = (bool) $config['delivery_enabled']
            ? $this->cleanText((string) ($config['delivery_text'] ?? ''))
            : '';
        if ($delivery === '') {
            $body = preg_replace('/^.*\{delivery\}.*(?:\R|$)/mu', '', $body) ?? $body;
        }

        $agent->loadMissing('organization');
        $currency = strtoupper(trim((string) data_get($product->metadata, 'currency', data_get($agent->organization?->settings, 'currency', 'GEL'))));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'GEL';
        $description = $this->cleanText(strip_tags((string) $product->description));
        $description = Str::limit(preg_replace('/\s+/u', ' ', $description) ?? '', $provider === 'instagram' ? 500 : 700, '…');
        $values = [
            'business_name' => $this->cleanText((string) ($agent->business_name ?: $agent->name)),
            'product_title' => $this->cleanText((string) $product->name),
            'product_description' => $description,
            'price' => number_format((float) $product->price, 2, '.', ' ').' '.$currency,
            'category' => $this->cleanText((string) $product->category),
            'delivery' => Str::limit($delivery, 600, '…'),
            'product_url' => $url,
        ];

        $limit = self::CHARACTER_LIMITS[$provider] ?? 2200;
        $caption = $this->replace($body, $values);
        foreach (['product_description', 'delivery'] as $shrinkable) {
            if (mb_strlen($caption) <= $limit) {
                break;
            }
            $current = $values[$shrinkable];
            $target = max(0, mb_strlen($current) - (mb_strlen($caption) - $limit) - 2);
            $values[$shrinkable] = $target > 0 ? Str::limit($current, $target, '…') : '';
            if ($shrinkable === 'delivery' && $values[$shrinkable] === '') {
                $body = preg_replace('/^.*\{delivery\}.*(?:\R|$)/mu', '', $body) ?? $body;
            }
            $caption = $this->replace($body, $values);
        }

        if (mb_strlen($caption) > $limit) {
            throw ValidationException::withMessages([
                'templates.'.$provider.'.body_template' => ucfirst($provider)." post is longer than {$limit} characters even after optional text is shortened.",
            ]);
        }

        return $caption;
    }

    /** @param array<string, string> $values */
    private function replace(string $body, array $values): string
    {
        $replacements = collect($values)->mapWithKeys(fn ($value, $token) => ['{'.$token.'}' => $value])->all();
        $caption = strtr($body, $replacements);
        $caption = preg_replace('/[ \t]+\R/u', "\n", $caption) ?? $caption;
        $caption = preg_replace('/\R{3,}/u', "\n\n", $caption) ?? $caption;

        return trim($caption);
    }

    private function cleanText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return trim($value);
    }

    private function publicHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
