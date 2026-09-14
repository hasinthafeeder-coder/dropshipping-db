<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ban_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_ban_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('event_type', 30);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_company_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('customer_ban_id')
                ->references('id')
                ->on('customer_bans')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('actor_company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('customer_ban_id');
            $table->index('customer_id');
            $table->index('event_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ban_events');
    }
};
