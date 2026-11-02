<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the invoice ↔ payment circular reference.
 *
 * supplier_settlement_invoices.effective_payment_id was created nullable without
 * a foreign key so supplier_payments (which references invoices) can be created first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_settlement_invoices', function (Blueprint $table) {
            $table->foreign('effective_payment_id', 'ssi_effective_payment_fk')
                ->references('id')
                ->on('supplier_payments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_settlement_invoices', function (Blueprint $table) {
            $table->dropForeign('ssi_effective_payment_fk');
        });
    }
};
