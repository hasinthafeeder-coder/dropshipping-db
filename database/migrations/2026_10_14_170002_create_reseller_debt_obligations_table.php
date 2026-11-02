<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditable reseller debt obligations for Minus Report foundations.
 *
 * Return fines and future explicit reseller deductions. Not a supplier invoice,
 * payment ledger, or wallet. Viewing reports never mutates outstanding balances.
 *
 * Uniqueness:
 * - source_idempotency_key: stable business identity (e.g. return_fine:order:{id})
 * - (obligation_type, order_id): at most one return fine per order
 *
 * Future Plus Report offsets must record an explicit reduction that lowers
 * outstanding_amount; report regeneration must never invent settlements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_debt_obligations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('obligation_number', 40)->unique();
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('reseller_company_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->string('obligation_type', 30);
            $table->string('return_reason', 30)->nullable();
            $table->decimal('original_amount', 15, 2);
            $table->decimal('outstanding_amount', 15, 2);
            $table->date('effective_date');
            $table->string('source_type', 40);
            $table->string('source_idempotency_key', 80)->unique();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique(['obligation_type', 'order_id'], 'rdo_type_order_unique');

            $table->foreign('reseller_id', 'rdo_reseller_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_company_id', 'rdo_reseller_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'rdo_order_fk')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('market_id', 'rdo_market_fk')
                ->references('id')
                ->on('markets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'rdo_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('recorded_by', 'rdo_recorded_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['reseller_id', 'effective_date'], 'rdo_reseller_effective_idx');
            $table->index(['market_id', 'currency_id'], 'rdo_market_currency_idx');
            $table->index('outstanding_amount', 'rdo_outstanding_idx');
            $table->index('obligation_type', 'rdo_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_debt_obligations');
    }
};
