<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market-scoped reseller settlement run (OPEN → LOCKED → CLOSED).
 *
 * Separate from supplier_settlement_batches. One non-voided DAILY per
 * market/date is enforced in services with market row locks (same pattern
 * as supplier settlements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_settlement_batches', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('batch_number', 40)->unique();
            $table->unsignedBigInteger('market_id');
            $table->string('market_code_snapshot', 10);
            $table->string('timezone_snapshot', 64);
            $table->date('settlement_date');
            $table->string('kind', 20);
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 20);
            $table->string('generation_source', 20);
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('generated_at');
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['market_id', 'settlement_date', 'kind', 'sequence'],
                'rsb_market_date_kind_seq_unique'
            );

            $table->foreign('market_id', 'rsb_market_fk')
                ->references('id')
                ->on('markets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('generated_by', 'rsb_generated_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('locked_by', 'rsb_locked_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('closed_by', 'rsb_closed_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('voided_by', 'rsb_voided_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['settlement_date', 'status'], 'rsb_date_status_idx');
            $table->index('market_id', 'rsb_market_idx');
            $table->index('status', 'rsb_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_settlement_batches');
    }
};
