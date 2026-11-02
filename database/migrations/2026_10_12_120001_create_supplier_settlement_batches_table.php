<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market-scoped settlement run (OPEN → LOCKED → CLOSED).
 *
 * Partial unique for "one non-voided DAILY per market/date" is deferred until
 * production VERSION() is recorded; enforce in services with row locks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_settlement_batches', function (Blueprint $table) {
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
                'ssb_market_date_kind_seq_unique'
            );

            $table->foreign('market_id', 'ssb_market_fk')
                ->references('id')
                ->on('markets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('generated_by', 'ssb_generated_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('locked_by', 'ssb_locked_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('closed_by', 'ssb_closed_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('voided_by', 'ssb_voided_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['settlement_date', 'status'], 'ssb_date_status_idx');
            $table->index('market_id', 'ssb_market_idx');
            $table->index('status', 'ssb_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_settlement_batches');
    }
};
