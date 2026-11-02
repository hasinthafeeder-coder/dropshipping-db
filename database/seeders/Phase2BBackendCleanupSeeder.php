<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Development cleanup: removes Phase 2B foundation seed rows only.
 * Does not touch UI prototypes or menus.
 *
 * Run:
 *   php artisan db:seed --class=Phase2BBackendCleanupSeeder
 */
class Phase2BBackendCleanupSeeder extends Seeder
{
    private const CUSTOMER_NAME_PREFIX = 'P2B Customer %';

    private const DISPATCH_NUMBERS = [
        'DO-20261005-001',
        'DO-20261005-002',
        'DO-20261006-001',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $orderIds = DB::table('orders')
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->where('customers.display_name', 'like', self::CUSTOMER_NAME_PREFIX)
                ->pluck('orders.id')
                ->all();

            if (Schema::hasTable('courier_payment_import_rows')) {
                if ($orderIds !== []) {
                    DB::table('courier_payment_import_rows')
                        ->whereIn('matched_order_id', $orderIds)
                        ->update(['matched_order_id' => null]);
                }

                DB::table('courier_payment_import_rows')
                    ->whereIn('courier_payment_import_id', function ($q) {
                        $q->select('id')
                            ->from('courier_payment_imports')
                            ->where('original_filename', 'like', 'p2b_%');
                    })
                    ->delete();

                DB::table('courier_payment_imports')
                    ->where('original_filename', 'like', 'p2b_%')
                    ->delete();
            }

            if (Schema::hasTable('dispatch_note_orders')) {
                DB::table('dispatch_note_orders')
                    ->whereIn('dispatch_note_id', function ($q) {
                        $q->select('id')
                            ->from('dispatch_notes')
                            ->whereIn('dispatch_number', self::DISPATCH_NUMBERS);
                    })
                    ->delete();

                DB::table('dispatch_notes')
                    ->whereIn('dispatch_number', self::DISPATCH_NUMBERS)
                    ->delete();
            }

            if ($orderIds === []) {
                $this->command?->info('Phase2BBackendCleanupSeeder: no P2B seed orders found.');

                return;
            }

            if (Schema::hasTable('dispatch_note_orders')) {
                DB::table('dispatch_note_orders')->whereIn('order_id', $orderIds)->delete();
            }

            DB::table('shipments')->whereIn('order_id', $orderIds)->delete();

            if (Schema::hasTable('order_fulfillment_items')) {
                DB::table('order_fulfillment_items')
                    ->whereIn('order_fulfillment_id', function ($q) use ($orderIds) {
                        $q->select('id')->from('order_fulfillments')->whereIn('order_id', $orderIds);
                    })
                    ->delete();
            }

            DB::table('order_fulfillment_events')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_fulfillments')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_status_histories')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_comments')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_cca_assignments')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_payment_submissions')->whereIn('order_id', $orderIds)->delete();
            DB::table('stock_reservations')->whereIn('order_id', $orderIds)->delete();
            DB::table('stock_consumptions')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_addresses')->whereIn('order_id', $orderIds)->delete();
            DB::table('orders')->whereIn('id', $orderIds)->delete();

            DB::table('customers')
                ->where('display_name', 'like', self::CUSTOMER_NAME_PREFIX)
                ->whereNotIn('id', function ($q) {
                    $q->select('customer_id')->from('orders');
                })
                ->delete();

            $this->command?->info('Phase2BBackendCleanupSeeder: removed '.count($orderIds).' P2B seed orders.');
        });
    }
}
