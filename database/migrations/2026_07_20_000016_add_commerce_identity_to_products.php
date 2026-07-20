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
            $table->foreignId('commerce_connection_id')->nullable()->after('agent_id')->constrained()->nullOnDelete();
            $table->string('external_product_id', 191)->nullable()->after('commerce_connection_id');
        });

        $seen = [];
        $validConnections = DB::table('commerce_connections')->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
        DB::table('products')->whereNotNull('metadata')->orderBy('id')->chunkById(500, function ($products) use (&$seen, $validConnections): void {
            foreach ($products as $product) {
                $metadata = json_decode((string) $product->metadata, true);
                $connectionId = (int) data_get($metadata, 'commerce_connection_id', 0);
                $externalId = trim((string) data_get($metadata, 'external_product_id', ''));
                $identity = $connectionId.':'.$externalId;
                if ($connectionId <= 0 || ! isset($validConnections[$connectionId]) || $externalId === '' || mb_strlen($externalId) > 191) {
                    continue;
                }
                if (isset($seen[$identity])) {
                    DB::table('products')->where('id', $product->id)->update(['is_active' => false]);

                    continue;
                }

                $seen[$identity] = true;
                DB::table('products')->where('id', $product->id)->update([
                    'commerce_connection_id' => $connectionId,
                    'external_product_id' => $externalId,
                ]);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['commerce_connection_id', 'external_product_id'], 'products_commerce_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_commerce_external_unique');
            $table->dropConstrainedForeignId('commerce_connection_id');
            $table->dropColumn('external_product_id');
        });
    }
};
