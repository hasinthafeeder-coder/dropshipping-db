<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circular FK: invoices.effective_payment_id → reseller_payments.id
 * (latest effective PAYMENT that advanced the invoice toward paid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_settlement_invoices', function (Blueprint $table) {
            $table->foreign('effective_payment_id', 'rsi_effective_payment_fk')
                ->references('id')
                ->on('reseller_payments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('reseller_settlement_invoices', function (Blueprint $table) {
            $table->dropForeign('rsi_effective_payment_fk');
        });
    }
};
