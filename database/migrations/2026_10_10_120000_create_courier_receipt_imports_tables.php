<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier receipt import preview + confirmation persistence.
 *
 * Replaces the obsolete Phase 2B courier_payment_imports schema (dropped by
 * 2026_10_06_120000_drop_phase2b_backend_foundation). Tables are created only
 * when missing so environments that already have the schema remain compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('courier_receipt_imports')) {
            Schema::create('courier_receipt_imports', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('courier_service_id');
                $table->unsignedBigInteger('uploaded_by');
                $table->string('original_filename');
                $table->string('file_type', 20);
                $table->string('stored_path')->nullable();
                $table->string('content_hash', 64);
                $table->string('format_id', 80);
                $table->unsignedInteger('entered_order_count');
                $table->decimal('entered_total_collected_cod', 15, 2);
                $table->decimal('entered_delivery_charge', 15, 2);
                $table->decimal('entered_net_income', 15, 2);
                $table->unsignedInteger('file_row_count')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('unmatched_count')->default(0);
                $table->unsignedInteger('ambiguous_count')->default(0);
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->unsignedInteger('previously_processed_count')->default(0);
                $table->unsignedInteger('service_mismatch_count')->default(0);
                $table->unsignedInteger('invalid_count')->default(0);
                $table->boolean('file_has_cod_amounts')->default(false);
                $table->boolean('file_has_delivery_charges')->default(false);
                $table->decimal('imported_cod_total', 15, 2)->nullable();
                $table->decimal('imported_delivery_charge_total', 15, 2)->nullable();
                $table->boolean('order_count_matches')->default(false);
                $table->boolean('cod_totals_match')->nullable();
                $table->boolean('delivery_charge_totals_match')->nullable();
                $table->boolean('net_income_consistent')->default(false);
                $table->boolean('totals_reconcile')->default(false);
                $table->json('reconciliation_notes')->nullable();
                $table->string('status', 30);
                $table->text('failure_reason')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->foreign('courier_service_id')
                    ->references('id')
                    ->on('courier_services')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->foreign('uploaded_by')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->index('courier_service_id');
                $table->index('uploaded_by');
                $table->index('status');
                $table->unique(
                    [
                        'content_hash',
                        'courier_service_id',
                        'entered_order_count',
                        'entered_total_collected_cod',
                        'entered_delivery_charge',
                    ],
                    'courier_receipt_imports_idempotency_unique'
                );
            });
        }

        if (! Schema::hasTable('courier_receipt_import_rows')) {
            Schema::create('courier_receipt_import_rows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('courier_receipt_import_id');
                $table->unsignedInteger('row_number');
                $table->string('raw_tracking_number')->nullable();
                $table->string('normalized_tracking_number')->nullable();
                $table->string('raw_order_number')->nullable();
                $table->string('raw_collected_cod')->nullable();
                $table->string('raw_delivery_charge')->nullable();
                $table->decimal('collected_cod', 15, 2)->nullable();
                $table->decimal('delivery_charge', 15, 2)->nullable();
                $table->string('match_result', 40);
                $table->unsignedBigInteger('matched_order_id')->nullable();
                $table->unsignedBigInteger('matched_shipment_id')->nullable();
                $table->unsignedBigInteger('matched_supplier_id')->nullable();
                $table->string('matched_order_number')->nullable();
                $table->string('matched_supplier_label')->nullable();
                $table->string('order_status_snapshot', 30)->nullable();
                $table->string('message')->nullable();
                $table->timestamps();

                $table->foreign('courier_receipt_import_id', 'crr_import_fk')
                    ->references('id')
                    ->on('courier_receipt_imports')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();

                $table->foreign('matched_order_id', 'crr_order_fk')
                    ->references('id')
                    ->on('orders')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();

                $table->foreign('matched_shipment_id', 'crr_shipment_fk')
                    ->references('id')
                    ->on('shipments')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();

                $table->foreign('matched_supplier_id', 'crr_supplier_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();

                $table->index(['courier_receipt_import_id', 'match_result'], 'crr_import_result_idx');
                $table->index('normalized_tracking_number', 'crr_tracking_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_receipt_import_rows');
        Schema::dropIfExists('courier_receipt_imports');
    }
};
