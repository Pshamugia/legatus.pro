<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LegatusSupportAssistant
{
    public const ORGANIZATION_SLUG = 'legatus-support';

    public const AGENT_SLUG = 'legatus-support';

    public function findActive(): ?Agent
    {
        return Agent::query()
            ->where('slug', self::AGENT_SLUG)
            ->where('is_active', true)
            ->first();
    }

    public function bootstrap(): Agent
    {
        return DB::transaction(function (): Agent {
            $organization = Organization::query()->updateOrCreate(
                ['slug' => self::ORGANIZATION_SLUG],
                [
                    'name' => 'Legatus',
                    'plan' => 'internal',
                    'settings' => ['currency' => 'USD', 'timezone' => 'Asia/Tbilisi'],
                ],
            );

            $agent = Agent::query()->firstOrNew(['slug' => self::AGENT_SLUG]);
            $settings = array_merge($agent->settings ?? [], [
                'assistant_mode' => 'platform_support',
                'human_handoff_enabled' => true,
                'website' => rtrim((string) config('app.url'), '/'),
                'widget_allowed_origins' => $this->widgetOrigins((string) config('app.url')),
                'widget_theme' => [
                    'preset' => 'forest',
                    'primary' => '#163c31',
                    'accent' => '#caff62',
                ],
            ]);

            $agent->fill([
                'organization_id' => $organization->id,
                'name' => 'Legatus Guide',
                'business_name' => 'Legatus',
                'industry' => 'software',
                'description' => 'Official Legatus product, onboarding, pricing, channels, and support assistant.',
                'tone' => 'clear, warm, concise, and practical',
                'channels' => ['web'],
                'settings' => $settings,
                'is_active' => true,
            ])->save();

            $source = $agent->knowledgeSources()->updateOrCreate(
                ['type' => 'manual', 'name' => 'Official Legatus product guide'],
                [
                    'source_scope' => 'business',
                    'status' => 'ready',
                    'progress' => 100,
                    'items_found' => count($this->knowledge()),
                    'items_created' => count($this->knowledge()),
                    'items_updated' => 0,
                    'error' => null,
                    'last_synced_at' => now(),
                    'content_hash' => hash('sha256', json_encode($this->knowledge(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ],
            );

            $source->chunks()->delete();
            foreach ($this->knowledge() as $chunk) {
                $contentHash = hash('sha256', $chunk['title']."\n".$chunk['content']);
                $source->chunks()->create([
                    'agent_id' => $agent->id,
                    'kind' => 'policy',
                    'title' => $chunk['title'],
                    'content' => $chunk['content'],
                    'content_hash' => $contentHash,
                    'metadata' => [
                        'url' => rtrim((string) config('app.url'), '/'),
                        'managed_by' => 'legatus:bootstrap-support-assistant',
                    ],
                ]);
            }

            $adminEmail = trim((string) config('legatus.super_admin_email'));
            $admin = $adminEmail !== '' ? User::query()->where('email', $adminEmail)->first() : null;
            if ($admin) {
                $organization->users()->syncWithoutDetaching([$admin->id => ['role' => 'owner']]);
            }
            if ($organization->billingAccessGrants()->active()->doesntExist()) {
                $organization->billingAccessGrants()->create([
                    'granted_by_user_id' => $admin?->id,
                    'kind' => 'complimentary',
                    'reason' => 'Internal Legatus website support workspace.',
                    'expires_at' => null,
                ]);
            }

            return $agent->fresh(['organization', 'knowledgeSources.chunks']);
        });
    }

    /** @return list<string> */
    private function widgetOrigins(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [];
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $origins = ["{$scheme}://{$host}{$port}"];
        if ($host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $alternate = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
            $origins[] = "{$scheme}://{$alternate}{$port}";
        }

        return array_values(array_unique($origins));
    }

    /** @return list<array{title: string, content: string}> */
    private function knowledge(): array
    {
        $supportEmail = trim((string) config('legatus.privacy_email'));

        return [
            [
                'title' => 'What Legatus is | რა არის Legatus',
                'content' => 'Legatus is a multi-business SaaS AI sales and shopping assistant. A business connects its own website, catalog, categories, rules, and communication channels. The assistant answers customers in the business voice and verifies commercial facts against that business\'s own data. Legatus არის მრავალბიზნესიანი SaaS AI გაყიდვებისა და შოპინგის ასისტენტი. ბიზნესი აკავშირებს საკუთარ ვებსაიტს, კატალოგს, კატეგორიებს, წესებსა და საკომუნიკაციო არხებს. ასისტენტი მომხმარებელს ბიზნესის სახელით პასუხობს და კომერციულ ფაქტებს მხოლოდ ამ ბიზნესის მონაცემებით ამოწმებს.',
            ],
            [
                'title' => 'Customer communication channels | საკომუნიკაციო არხები',
                'content' => 'Legatus Chat supports a website chat widget and customer conversations from Facebook Messenger, Instagram Direct, and WhatsApp Business after the business connects each channel. The same channel-neutral conversation engine preserves context and tenant boundaries. Legatus Chat მხარს უჭერს ვებსაიტის ჩატს, Facebook Messenger-ს, Instagram Direct-სა და WhatsApp Business-ს მას შემდეგ, რაც ბიზნესი თითოეულ არხს დააკავშირებს. ყველა არხი ერთიან საუბრის ძრავს იყენებს და ბიზნესების მონაცემები ერთმანეთისგან იზოლირებულია.',
            ],
            [
                'title' => 'Social publishing and product images | სოციალური გამოქვეყნება და ფოტოები',
                'content' => 'Legatus Social can schedule verified catalog product posts for connected social channels. A business may publish the storefront product image unchanged or enable AI Photo Editor to create a social-ready visual while preserving the real product. AI Copywriter can prepare channel-specific text from verified product data. Successful Facebook and Instagram feed posts can also publish the final image as a Story. Legatus Social-ს შეუძლია დაკავშირებულ სოციალურ არხებში კატალოგის გადამოწმებული პროდუქტების დაგეგმილი გამოქვეყნება. ბიზნესს შეუძლია საიტის პროდუქტის ფოტო უცვლელად გამოაქვეყნოს ან ჩართოს AI Photo Editor, რომელიც რეალური პროდუქტის შენარჩუნებით ამზადებს სოციალურ ვიზუალს. AI Copywriter გადამოწმებული პროდუქტის მონაცემებით წერს არხისთვის შესაბამის ტექსტს. Facebook-სა და Instagram-ზე წარმატებულ feed პოსტს იგივე საბოლოო ფოტო Story-დაც შეუძლია გამოაქვეყნოს.',
            ],
            [
                'title' => 'Setup and onboarding | გამართვა და დაწყება',
                'content' => 'To start, create a Legatus account, add the business name and AI assistant name, connect the public website or catalog, review the imported knowledge, install the website widget if needed, and connect the communication or publishing channels the business wants to use. A business controls its own workspace, branding, knowledge, and channel connections. დასაწყებად საჭიროა Legatus-ის ანგარიშის შექმნა, ბიზნესისა და AI ასისტენტის სახელების მითითება, საჯარო ვებსაიტის ან კატალოგის დაკავშირება, შემოტანილი ცოდნის გადამოწმება, საჭიროების შემთხვევაში ვებსაიტის widget-ის დაყენება და სასურველი საკომუნიკაციო ან გამოსაქვეყნებელი არხების დაკავშირება. თითოეული ბიზნესი საკუთარ workspace-ს, ბრენდინგს, ცოდნასა და არხებს მართავს.',
            ],
            [
                'title' => 'Plans and pricing | გეგმები და ფასები',
                'content' => 'Legatus Chat costs $30 monthly, $162 for six months, or $288 yearly. Legatus Chat + Social costs $60 monthly, $324 for six months, or $576 yearly. The annual offer includes an online store. Legatus Chat ღირს $30 თვეში, $162 ექვს თვეში ან $288 წელიწადში. Legatus Chat + Social ღირს $60 თვეში, $324 ექვს თვეში ან $576 წელიწადში. წლიურ შეთავაზებაში შედის ონლაინ მაღაზია.',
            ],
            [
                'title' => 'Support and verified answers | მხარდაჭერა და გადამოწმებული პასუხები',
                'content' => "This assistant answers questions about Legatus features, plans, setup, channels, and suitability for a business. If a verified answer is unavailable or a person is requested, the conversation may be handed to the Legatus team. Support contact: {$supportEmail}. ეს ასისტენტი პასუხობს Legatus-ის ფუნქციების, გეგმების, გამართვის, არხებისა და ბიზნესისთვის შესაბამისობის შესახებ კითხვებს. თუ გადამოწმებული პასუხი ვერ მოიძებნა ან მომხმარებელს ადამიანთან საუბარი სურს, საუბარი შეიძლება Legatus-ის გუნდს გადაეცეს. მხარდაჭერის კონტაქტი: {$supportEmail}.",
            ],
        ];
    }
}
