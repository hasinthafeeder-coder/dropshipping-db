<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reseller bank-transfer submissions for later admin review/approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->string('method', 30);
            $table->string('review_status', 30)->default('PENDING_REVIEW');
            $table->string('reference_number', 100)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('slip_path')->nullable();
            $table->string('slip_original_name')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->unsignedBigInteger('submitted_by_company_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('submitted_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('submitted_by_company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['order_id', 'review_status']);
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_submissions');
    }
};
