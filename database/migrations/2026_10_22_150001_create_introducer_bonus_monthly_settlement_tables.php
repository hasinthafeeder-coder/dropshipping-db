<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly introducer bonus ledger.
 *
 * Separate from supplier_settlement_* and reseller_settlement_*.
 * One batch per market/year/month. One line per qualifying team order.
 * One full payment per batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('introducer_bonus_monthly_batches', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('batch_number', 40)->unique();
            $table->unsignedBigInteger('market_id');
            $table->string('market_code_snapshot', 10);
            $table->string('timezone_snapshot', 64);
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('bonus_rate_snapshot', 15, 2);
            $table->decimal('payable_total', 15, 2);
            $table->unsignedInteger('line_count');
            $table->string('status', 20);
            $table->string('generation_source', 20);
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('generated_at');
            $table->unsignedBigInteger('rebuilt_by')->nullable();
            $table->timestamp('rebuilt_at')->nullable();
            $table->unsignedInteger('rebuild_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['market_id', 'period_year', 'period_month'],
                'ibm_batch_market_period_unique'
            );

            $table->foreign('market_id', 'ibm_batch_market_fk')
                ->references('id')->on('markets')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('currency_id', 'ibm_batch_currency_fk')
                ->references('id')->on('currencies')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('generated_by', 'ibm_batch_generated_by_fk')
                ->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('rebuilt_by', 'ibm_batch_rebuilt_by_fk')
                ->references('id')->on('users')->nullOnDelete()->cascadeOnUpdate();

            $table->index('status', 'ibm_batch_status_idx');
        });

        Schema::create('introducer_bonus_lines', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('introducer_user_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('referral_relationship_id');
            $table->date('business_date');
            $table->unsignedBigInteger('courier_receipt_import_id');
            $table->unsignedBigInteger('courier_receipt_import_row_id');
            $table->decimal('bonus_rate_snapshot', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->string('currency_code_snapshot', 3);
            $table->string('order_number_snapshot')->nullable();
            $table->timestamps();

            $table->unique('order_id', 'ibl_order_unique');
            $table->index(['batch_id', 'introducer_user_id'], 'ibl_batch_introducer_idx');

            $table->foreign('batch_id', 'ibl_batch_fk')
                ->references('id')->on('introducer_bonus_monthly_batches')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('introducer_user_id', 'ibl_introducer_fk')
                ->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('order_id', 'ibl_order_fk')
                ->references('id')->on('orders')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('referral_relationship_id', 'ibl_relationship_fk')
                ->references('id')->on('referral_relationships')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('courier_receipt_import_id', 'ibl_import_fk')
                ->references('id')->on('courier_receipt_imports')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('courier_receipt_import_row_id', 'ibl_import_row_fk')
                ->references('id')->on('courier_receipt_import_rows')->restrictOnDelete()->cascadeOnUpdate();
        });

        Schema::create('introducer_bonus_payments', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('batch_id');
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->date('paid_on');
            $table->string('reference', 100);
            $table->unsignedBigInteger('recorded_by');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique('batch_id', 'ibp_batch_unique');

            $table->foreign('batch_id', 'ibp_batch_fk')
                ->references('id')->on('introducer_bonus_monthly_batches')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('currency_id', 'ibp_currency_fk')
                ->references('id')->on('currencies')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('recorded_by', 'ibp_recorded_by_fk')
                ->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();
        });

        Schema::create('introducer_bonus_events', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('event_type', 40);
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'event_type'], 'ibe_batch_type_idx');
            $table->index('subject_user_id', 'ibe_subject_idx');

            $table->foreign('batch_id', 'ibe_batch_fk')
                ->references('id')->on('introducer_bonus_monthly_batches')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('subject_user_id', 'ibe_subject_fk')
                ->references('id')->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('actor_user_id', 'ibe_actor_fk')
                ->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('introducer_bonus_events');
        Schema::dropIfExists('introducer_bonus_payments');
        Schema::dropIfExists('introducer_bonus_lines');
        Schema::dropIfExists('introducer_bonus_monthly_batches');
    }
};
