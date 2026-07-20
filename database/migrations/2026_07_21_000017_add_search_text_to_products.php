<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('search_text')->nullable()->after('description');
        });

        DB::table('products')
            ->select(['id', 'name', 'sku', 'category', 'description', 'metadata'])
            ->orderBy('id')
            ->chunkById(500, function ($products): void {
                foreach ($products as $product) {
                    $metadata = is_string($product->metadata)
                        ? json_decode($product->metadata, true)
                        : null;
                    $parts = [
                        $product->name,
                        $product->sku,
                        $product->category,
                        $product->description,
                        data_get($metadata, 'author'),
                        data_get($metadata, 'genres'),
                        data_get($metadata, 'tags'),
                        data_get($metadata, 'brand'),
                        data_get($metadata, 'publisher'),
                        data_get($metadata, 'isbn'),
                        data_get($metadata, 'isbn_10'),
                        data_get($metadata, 'isbn_13'),
                        data_get($metadata, 'language'),
                        data_get($metadata, 'condition'),
                    ];

                    DB::table('products')->where('id', $product->id)->update([
                        'search_text' => $this->searchText($parts),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }

    private function searchText(array $parts): ?string
    {
        $values = [];
        array_walk_recursive($parts, function ($value) use (&$values): void {
            if (is_scalar($value) && ! is_bool($value)) {
                $values[] = strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        });
        $text = preg_replace('/\s+/u', ' ', trim(implode(' ', $values))) ?? '';

        return $text === '' ? null : mb_substr($text, 0, 32_000);
    }
};
