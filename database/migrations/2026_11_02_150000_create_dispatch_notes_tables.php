<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier handover batch tracking for Supplier Dispatch Summary.
 *
 * Additive only: does not alter order status, stock, remittance, or settlement.
 * An order may appear on at most one dispatch note (unique order_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dispatch_notes')) {
            Schema::create('dispatch_notes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('dispatch_number', 40)->unique();
                $table->unsignedBigInteger('supplier_id');
                $table->unsignedBigInteger('courier_id')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedInteger('orders_count')->default(0);
                $table->decimal('total_cod_amount', 15, 2)->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->timestamp('handed_over_at');
                $table->timestamps();

                $table->foreign('supplier_id', 'dispatch_notes_supplier_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('courier_id', 'dispatch_notes_courier_fk')
                    ->references('id')->on('couriers')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('created_by', 'dispatch_notes_created_by_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->index(['supplier_id', 'handed_over_at'], 'dispatch_notes_supplier_handed_idx');
                $table->index(['supplier_id', 'courier_id'], 'dispatch_notes_supplier_courier_idx');
            });
        }

        if (! Schema::hasTable('dispatch_note_orders')) {
            Schema::create('dispatch_note_orders', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('dispatch_note_id');
                $table->unsignedBigInteger('order_id');
                $table->timestamps();

                $table->foreign('dispatch_note_id', 'dispatch_note_orders_note_fk')
                    ->references('id')->on('dispatch_notes')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('order_id', 'dispatch_note_orders_order_fk')
                    ->references('id')->on('orders')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->unique('order_id', 'dispatch_note_orders_order_unique');
                $table->index('dispatch_note_id', 'dispatch_note_orders_note_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_note_orders');
        Schema::dropIfExists('dispatch_notes');
    }
};
