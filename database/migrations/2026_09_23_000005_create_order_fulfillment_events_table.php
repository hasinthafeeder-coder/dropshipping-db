<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fulfillment_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_fulfillment_id')->nullable();
            $table->string('event_type', 50);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_fulfillment_id')
                ->references('id')
                ->on('order_fulfillments')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['order_id', 'created_at']);
            $table->index('order_fulfillment_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_events');
    }
};
