<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted courier remittance evidence.
 *
 * Delivery confirmation and company cash receipt stay separate facts.
 * An applied row records the source import row, the status transition, and
 * only the amounts the file actually provided. Re-application is rejected
 * by the unique order and waybill keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('courier_receipt_imports')
            && ! Schema::hasColumn('courier_receipt_imports', 'already_applied_count')) {
            Schema::table('courier_receipt_imports', function (Blueprint $table) {
                $table->unsignedInteger('already_applied_count')->default(0)->after('invalid_count');
            });
        }

        if (Schema::hasTable('courier_receipt_import_rows')) {
            Schema::table('courier_receipt_import_rows', function (Blueprint $table) {
                if (! Schema::hasColumn('courier_receipt_import_rows', 'gross_remittance_amount')) {
                    $table->decimal('gross_remittance_amount', 15, 2)->nullable()->after('delivery_charge');
                }
                if (! Schema::hasColumn('courier_receipt_import_rows', 'courier_charge_amount')) {
                    $table->decimal('courier_charge_amount', 15, 2)->nullable()->after('gross_remittance_amount');
                }
                if (! Schema::hasColumn('courier_receipt_import_rows', 'net_remittance_amount')) {
                    $table->decimal('net_remittance_amount', 15, 2)->nullable()->after('courier_charge_amount');
                }
                if (! Schema::hasColumn('courier_receipt_import_rows', 'remittance_currency')) {
                    $table->char('remittance_currency', 3)->nullable()->after('net_remittance_amount');
                }
                if (! Schema::hasColumn('courier_receipt_import_rows', 'remittance_business_date')) {
                    $table->date('remittance_business_date')->nullable()->after('remittance_currency');
                }
                if (! Schema::hasColumn('courier_receipt_import_rows', 'remittance_provided_fields')) {
                    $table->json('remittance_provided_fields')->nullable()->after('remittance_business_date');
                }
                if (! Schema::hasColumn('courier_receipt_import_rows', 'applied_at')) {
                    $table->timestamp('applied_at')->nullable()->after('message');
                }
                if (! Schema::hasColumn('courier_receipt_import_rows', 'applied_by')) {
                    $table->unsignedBigInteger('applied_by')->nullable()->after('applied_at');
                }
            });
        }

        if (! Schema::hasTable('courier_remittance_applications')) {
            Schema::create('courier_remittance_applications', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('courier_receipt_import_id');
                $table->unsignedBigInteger('courier_receipt_import_row_id');
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('shipment_id');
                $table->unsignedBigInteger('courier_service_id');
                $table->string('normalized_tracking_number', 191);
                $table->decimal('gross_remittance_amount', 15, 2)->nullable();
                $table->decimal('courier_charge_amount', 15, 2)->nullable();
                $table->decimal('net_remittance_amount', 15, 2)->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->date('business_date')->nullable();
                $table->string('order_status_before', 30);
                $table->string('order_status_after', 30);
                $table->unsignedBigInteger('order_status_history_id')->nullable();
                $table->unsignedBigInteger('applied_by');
                $table->timestamp('applied_at');
                $table->timestamps();

                $table->unique('courier_receipt_import_row_id', 'cra_row_unique');
                $table->unique('order_id', 'cra_order_unique');
                $table->unique(
                    ['courier_service_id', 'normalized_tracking_number'],
                    'cra_service_tracking_unique'
                );

                $table->foreign('courier_receipt_import_id', 'cra_import_fk')
                    ->references('id')->on('courier_receipt_imports')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('courier_receipt_import_row_id', 'cra_row_fk')
                    ->references('id')->on('courier_receipt_import_rows')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('order_id', 'cra_order_fk')
                    ->references('id')->on('orders')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('shipment_id', 'cra_shipment_fk')
                    ->references('id')->on('shipments')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('courier_service_id', 'cra_service_fk')
                    ->references('id')->on('courier_services')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('order_status_history_id', 'cra_history_fk')
                    ->references('id')->on('order_status_histories')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('applied_by', 'cra_applied_by_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()->cascadeOnUpdate();
            });
        }

        if (Schema::hasTable('courier_receipt_import_rows')
            && Schema::hasColumn('courier_receipt_import_rows', 'applied_by')) {
            Schema::table('courier_receipt_import_rows', function (Blueprint $table) {
                $foreign = collect(Schema::getForeignKeys('courier_receipt_import_rows'))
                    ->contains(static fn (array $key): bool => ($key['name'] ?? '') === 'crr_applied_by_fk');

                if (! $foreign) {
                    $table->foreign('applied_by', 'crr_applied_by_fk')
                        ->references('id')->on('users')
                        ->nullOnDelete()->cascadeOnUpdate();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_remittance_applications');

        if (Schema::hasTable('courier_receipt_import_rows')) {
            Schema::table('courier_receipt_import_rows', function (Blueprint $table) {
                if (Schema::hasColumn('courier_receipt_import_rows', 'applied_by')) {
                    $table->dropForeign('crr_applied_by_fk');
                }

                $columns = [
                    'applied_by',
                    'applied_at',
                    'remittance_provided_fields',
                    'remittance_business_date',
                    'remittance_currency',
                    'net_remittance_amount',
                    'courier_charge_amount',
                    'gross_remittance_amount',
                ];

                foreach ($columns as $column) {
                    if (Schema::hasColumn('courier_receipt_import_rows', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('courier_receipt_imports')
            && Schema::hasColumn('courier_receipt_imports', 'already_applied_count')) {
            Schema::table('courier_receipt_imports', function (Blueprint $table) {
                $table->dropColumn('already_applied_count');
            });
        }
    }
};
