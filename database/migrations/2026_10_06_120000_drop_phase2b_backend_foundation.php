<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes Phase 2B backend foundation schema added earlier.
 * UI prototypes and menu entries are intentionally retained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('courier_payment_import_rows');
        Schema::dropIfExists('courier_payment_imports');
        Schema::dropIfExists('dispatch_note_orders');
        Schema::dropIfExists('dispatch_notes');

        if (! Schema::hasColumn('orders', 'payment_status')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_settled_by')) {
                try {
                    $table->dropForeign(['payment_settled_by']);
                } catch (\Throwable) {
                    // FK may already be absent on some environments.
                }
            }
        });

        $this->dropOrdersIndexIfExists('orders_payment_status_index');
        $this->dropOrdersIndexIfExists('orders_status_payment_status_index');
        $this->dropOrdersIndexIfExists('orders_payment_received_at_index');
        $this->dropOrdersIndexIfExists('orders_payment_settled_at_index');

        Schema::table('orders', function (Blueprint $table) {
            $columns = array_values(array_filter([
                'payment_status',
                'payment_received_amount',
                'payment_received_at',
                'payment_source',
                'payment_settled_at',
                'payment_settled_by',
            ], static fn (string $column): bool => Schema::hasColumn('orders', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        // Clear orphaned migration batch entries for deleted foundation files.
        DB::table('migrations')
            ->whereIn('migration', [
                '2026_10_06_000001_add_phase2b_payment_lifecycle_to_orders_table',
                '2026_10_06_000002_create_dispatch_notes_tables',
                '2026_10_06_000003_create_courier_payment_imports_tables',
            ])
            ->delete();
    }

    public function down(): void
    {
        // Intentionally empty — Phase 2B backend foundation will be reintroduced later.
    }

    private function dropOrdersIndexIfExists(string $indexName): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS ok
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1',
            ['orders', $indexName]
        );

        if ($exists) {
            DB::statement('ALTER TABLE `orders` DROP INDEX `'.$indexName.'`');
        }
    }
};
