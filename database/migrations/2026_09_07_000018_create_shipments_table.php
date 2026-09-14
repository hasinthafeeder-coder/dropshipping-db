<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('courier_id');
            $table->unsignedBigInteger('courier_service_id');
            $table->unsignedBigInteger('courier_city_id');
            $table->unsignedBigInteger('supplier_courier_account_id')->nullable();
            $table->string('tracking_number')->nullable();
            $table->decimal('weight_snapshot', 12, 3)->default(0);
            $table->decimal('courier_fee_snapshot', 15, 2)->default(0);
            $table->unsignedBigInteger('currency_id');
            $table->json('pricing_rule_snapshot')->nullable();
            $table->string('status', 30);
            $table->timestamp('booked_at')->nullable();
            $table->unsignedBigInteger('booked_by')->nullable();
            $table->string('external_booking_ref', 150)->nullable();
            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_id')
                ->references('id')
                ->on('couriers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_service_id')
                ->references('id')
                ->on('courier_services')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_city_id')
                ->references('id')
                ->on('courier_cities')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_courier_account_id')
                ->references('id')
                ->on('supplier_courier_accounts')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('booked_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('supplier_id');
            $table->index('courier_id');
            $table->index('status');
            $table->index('tracking_number');
            $table->index('booked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
