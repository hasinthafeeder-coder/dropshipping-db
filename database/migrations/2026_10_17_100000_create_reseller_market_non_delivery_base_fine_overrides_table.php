<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-reseller BASE non-delivery fine override (market-native currency).
 *
 * Nullable absence means use the market global base default.
 * Combination fine totals (after-hours / processing override / both) are never overridden here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_market_non_delivery_base_fine_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('market_id');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['user_id', 'market_id'], 'rmndbfo_user_market_unique');

            $table->foreign('user_id', 'rmndbfo_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('market_id', 'rmndbfo_market_fk')
                ->references('id')
                ->on('markets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_market_non_delivery_base_fine_overrides');
    }
};
