<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class ReliabilityFixtureManager
{
    public const ORGANIZATION_SLUG = 'legatus-reliability-fixture';

    public const AGENT_SLUG = 'legatus-reliability-fixture';

    public function provision(): Agent
    {
        return DB::transaction(function (): Agent {
            $organization = Organization::updateOrCreate(
                ['slug' => self::ORGANIZATION_SLUG],
                [
                    'name' => 'Legatus Reliability Fixture',
                    'plan' => 'system',
                    'settings' => ['system_fixture' => 'reliability'],
                ],
            );
            $agent = $organization->agents()->updateOrCreate(
                ['slug' => self::AGENT_SLUG],
                [
                    'name' => 'Reliability Assistant',
                    'business_name' => 'Reliability Fixture Store',
                    'industry' => 'retail',
                    'description' => 'A private platform-owned fixture used only for automated reliability checks.',
                    'tone' => 'warm, concise and expert',
                    'channels' => [],
                    'settings' => [
                        'system_fixture' => 'reliability',
                        'language' => 'en',
                        'human_handoff_enabled' => true,
                        'handoff_threshold' => .72,
                        'discount_limit' => 10,
                        'delivery_policy' => [
                            'timezone' => 'Asia/Tbilisi',
                            'local_cities' => ['Tbilisi'],
                            'cutoff' => '18:00',
                            'local_business_days' => 1,
                            'regional_min_business_days' => 1,
                            'regional_max_business_days' => 3,
                        ],
                    ],
                    'is_active' => false,
                ],
            );

            $agent->knowledgeSources()->delete();
            $catalog = $agent->knowledgeSources()->create([
                'type' => 'csv',
                'name' => 'Reliability verified catalog',
                'status' => 'ready',
                'progress' => 100,
                'items_found' => 3,
                'items_created' => 3,
                'last_synced_at' => now(),
            ]);
            $policy = $agent->knowledgeSources()->create([
                'type' => 'text',
                'name' => 'Reliability business policies',
                'status' => 'ready',
                'progress' => 100,
                'items_found' => 2,
                'items_created' => 2,
                'last_synced_at' => now(),
            ]);

            $products = [
                [
                    'sku' => 'BK-1001',
                    'name' => 'Piranesi',
                    'category' => 'Magical realism',
                    'description' => 'A mysterious, atmospheric modern novel set in an unusual world.',
                    'price' => 27.50,
                    'stock' => 7,
                    'metadata' => ['author' => 'Susanna Clarke', 'mood' => ['mysterious', 'atmospheric']],
                ],
                [
                    'sku' => 'BK-1008',
                    'name' => 'Before the Coffee Gets Cold',
                    'category' => 'Magical realism',
                    'description' => 'A warm modern novel about a cafe where visitors can revisit the past.',
                    'price' => 26.90,
                    'stock' => 9,
                    'metadata' => ['author' => 'Toshikazu Kawaguchi', 'mood' => ['warm', 'emotional']],
                ],
                [
                    'sku' => 'BK-1010',
                    'name' => 'Klara and the Sun',
                    'category' => 'Science fiction',
                    'description' => 'A reflective modern novel about artificial intelligence, love and hope.',
                    'price' => 28.90,
                    'stock' => 5,
                    'metadata' => ['author' => 'Kazuo Ishiguro', 'mood' => ['reflective', 'tender']],
                ],
            ];
            $agent->products()->update(['is_active' => false]);
            foreach ($products as $product) {
                $metadata = array_merge($product['metadata'], ['source_id' => $catalog->id]);
                $agent->products()->updateOrCreate(
                    ['sku' => $product['sku']],
                    array_merge($product, [
                        'metadata' => $metadata,
                        'search_text' => mb_strtolower(implode(' ', [
                            $product['name'],
                            $product['category'],
                            $product['description'],
                            data_get($metadata, 'author'),
                            implode(' ', data_get($metadata, 'mood', [])),
                        ])),
                        'is_active' => true,
                    ]),
                );
            }

            foreach ([
                ['Delivery policy', 'Orders confirmed before 18:00 in Tbilisi may arrive on the next business day. Regional delivery takes one to three business days.'],
                ['Wholesale policy', 'Wholesale orders start at 10 units. Discounts above 10 percent require manager approval.'],
            ] as [$title, $content]) {
                $policy->chunks()->create([
                    'agent_id' => $agent->id,
                    'kind' => 'policy',
                    'title' => $title,
                    'content' => $content,
                    'content_hash' => hash('sha256', $title.'|'.$content),
                    'metadata' => ['system_fixture' => 'reliability'],
                ]);
            }

            return $agent->fresh();
        });
    }
}
