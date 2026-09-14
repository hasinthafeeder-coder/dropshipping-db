<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('author_user_id');
            $table->unsignedBigInteger('author_company_id')->nullable();
            $table->string('context_type', 30);
            $table->string('context_ref', 100)->nullable();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('author_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('author_company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('order_id');
            $table->index('customer_id');
            $table->index('context_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_comments');
    }
};
