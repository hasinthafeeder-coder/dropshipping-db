<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Create Order Phase 1.1 fields.
 *
 * - order_type defaults to NEW so existing rows remain compatible.
 * - draft courier FKs are nullable selections only (not shipments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_type', 30)
                ->default('NEW')
                ->after('source');

            $table->unsignedBigInteger('draft_courier_id')
                ->nullable()
                ->after('available_in_pool');

            $table->unsignedBigInteger('draft_courier_service_id')
                ->nullable()
                ->after('draft_courier_id');

            $table->unsignedBigInteger('draft_courier_city_id')
                ->nullable()
                ->after('draft_courier_service_id');

            $table->foreign('draft_courier_id')
                ->references('id')
                ->on('couriers')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('draft_courier_service_id')
                ->references('id')
                ->on('courier_services')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('draft_courier_city_id')
                ->references('id')
                ->on('courier_cities')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('order_type');
            $table->index('draft_courier_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['draft_courier_id']);
            $table->dropForeign(['draft_courier_service_id']);
            $table->dropForeign(['draft_courier_city_id']);
            $table->dropIndex(['order_type']);
            $table->dropIndex(['draft_courier_id']);
            $table->dropColumn([
                'order_type',
                'draft_courier_id',
                'draft_courier_service_id',
                'draft_courier_city_id',
            ]);
        });
    }
};
