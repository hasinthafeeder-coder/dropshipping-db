<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('suggested_price_min', 15, 2)->nullable()->after('suggested_price');
            $table->decimal('suggested_price_max', 15, 2)->nullable()->after('suggested_price_min');
        });

        DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.price_locked', false)
            ->whereNotNull('product_variants.suggested_price')
            ->update([
                'product_variants.suggested_price_min' => DB::raw('product_variants.suggested_price'),
                'product_variants.suggested_price_max' => DB::raw('product_variants.suggested_price'),
            ]);
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['suggested_price_min', 'suggested_price_max']);
        });
    }
};
