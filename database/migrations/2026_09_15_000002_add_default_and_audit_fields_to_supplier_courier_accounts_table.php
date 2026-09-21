<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds default-courier + admin accountability columns.
     *
     * At-most-one active default per supplier is enforced with a functional
     * unique index (same MariaDB/MySQL pattern as customer_bans):
     * active marker = supplier_id when is_default AND is_active, else NULL.
     */
    public function up(): void
    {
        Schema::table('supplier_courier_accounts', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->unsignedBigInteger('created_by')->nullable()->after('is_default');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['supplier_id', 'is_default']);
        });

        DB::statement('
            CREATE UNIQUE INDEX supplier_courier_accounts_active_default_unique
            ON supplier_courier_accounts ((CASE WHEN `is_default` = 1 AND `is_active` = 1 THEN `supplier_id` END))
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX supplier_courier_accounts_active_default_unique ON supplier_courier_accounts');

        Schema::table('supplier_courier_accounts', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropIndex(['supplier_id', 'is_default']);
            $table->dropColumn(['is_default', 'created_by', 'updated_by']);
        });
    }
};
