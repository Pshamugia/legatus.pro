<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\SocialMediaTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialMediaTemplateService
{
    public const PROVIDERS = ['facebook', 'instagram'];

    public const IMAGE_STYLES = ['original', 'storefront', 'raw', 'framed', 'editorial', 'dark', 'brand'];

    public function __construct(private readonly SocialMediaTemplateRenderer $renderer) {}

    /** @return array<string, array<string, mixed>> */
    public function configurations(Agent $agent): array
    {
        $saved = $agent->socialMediaTemplates()->get()->keyBy('provider');
        $delivery = $this->defaultDeliveryText($agent);

        return collect(self::PROVIDERS)->mapWithKeys(function (string $provider) use ($agent, $saved, $delivery): array {
            /** @var SocialMediaTemplate|null $template */
            $template = $saved->get($provider);

            return [$provider => $template ? [
                'id' => $template->id,
                'provider' => $provider,
                'body_template' => $template->body_template,
                'delivery_enabled' => (bool) $template->delivery_enabled,
                'delivery_text' => $template->delivery_text,
                'image_style' => in_array($template->image_style, self::IMAGE_STYLES, true) ? $template->image_style : 'original',
                'version' => (int) $template->version,
            ] : [
                'id' => null,
                'provider' => $provider,
                'body_template' => $this->defaultBody($agent, $provider),
                'delivery_enabled' => $delivery !== '',
                'delivery_text' => $delivery,
                'image_style' => 'original',
                'version' => 0,
            ]];
        })->all();
    }

    /** @param array<string, array<string, mixed>> $templates */
    public function save(Agent $agent, User $user, array $templates): void
    {
        foreach (self::PROVIDERS as $provider) {
            $this->renderer->validate($provider, $templates[$provider] ?? [], 'templates.'.$provider);
        }

        DB::transaction(function () use ($agent, $user, $templates): void {
            foreach (self::PROVIDERS as $provider) {
                $existing = $agent->socialMediaTemplates()->where('provider', $provider)->lockForUpdate()->first();
                $values = [
                    'body_template' => trim((string) $templates[$provider]['body_template']),
                    'delivery_enabled' => (bool) ($templates[$provider]['delivery_enabled'] ?? false),
                    'delivery_text' => filled($templates[$provider]['delivery_text'] ?? null)
                        ? trim((string) $templates[$provider]['delivery_text'])
                        : null,
                    'image_style' => in_array($templates[$provider]['image_style'] ?? null, self::IMAGE_STYLES, true)
                        ? $templates[$provider]['image_style']
                        : 'original',
                    'version' => $existing ? ((int) $existing->version + 1) : 1,
                    'updated_by_user_id' => $user->id,
                ];

                if ($existing) {
                    $existing->update($values);
                } else {
                    $agent->socialMediaTemplates()->create(['provider' => $provider, ...$values]);
                }
            }
        });
    }

    /** @return array<string, array<string, mixed>> */
    public function snapshots(Agent $agent, array $providers): array
    {
        $configurations = $this->configurations($agent);

        return collect($providers)->mapWithKeys(function (string $provider) use ($configurations): array {
            $configuration = $configurations[$provider];

            return [$provider => [
                'template_id' => $configuration['id'],
                'version' => $configuration['version'],
                'body_template' => $configuration['body_template'],
                'delivery_enabled' => $configuration['delivery_enabled'],
                'delivery_text' => $configuration['delivery_text'],
                'image_style' => $configuration['image_style'],
                'snapshotted_at' => now()->toIso8601String(),
            ]];
        })->all();
    }

    private function defaultBody(Agent $agent, string $provider): string
    {
        $language = mb_strtolower((string) data_get($agent->settings, 'language', 'en'));
        if (Str::startsWith($language, 'ka')) {
            $callToAction = $provider === 'instagram' ? '🔗 დეტალები:' : '✅ შესაძენად გადადით საიტზე:';

            return "✨ {product_title}\n\n{product_description}\n\n💰 {price}\n🚚 {delivery}\n\n{$callToAction}\n{product_url}";
        }

        $callToAction = $provider === 'instagram' ? '🔗 Product details:' : '✅ View and buy on our website:';

        return "✨ {product_title}\n\n{product_description}\n\n💰 {price}\n🚚 {delivery}\n\n{$callToAction}\n{product_url}";
    }

    private function defaultDeliveryText(Agent $agent): string
    {
        $source = $agent->knowledgeSources()->where('source_scope', 'delivery')->where('status', 'ready')->first();
        if (! $source) {
            return '';
        }

        $content = (string) $source->chunks()->where('kind', 'policy')->value('content');
        $content = preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '';

        return Str::limit(trim($content), 400, '…');
    }
}
