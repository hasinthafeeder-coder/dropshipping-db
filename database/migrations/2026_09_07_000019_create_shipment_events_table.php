<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('shipment_id');
            $table->string('external_status', 100)->nullable();
            $table->string('normalized_status', 50)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('event_at');
            $table->json('raw_response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('shipment_id')
                ->references('id')
                ->on('shipments')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->index('shipment_id');
            $table->index('normalized_status');
            $table->index('event_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
    }
};
