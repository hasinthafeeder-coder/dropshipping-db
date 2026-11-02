<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditable non-delivery fine condition snapshot on debt obligations.
 *
 * BASE | AFTER_HOURS | PROCESSING_OVERRIDE | COMBINED
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_debt_obligations', function (Blueprint $table) {
            $table->string('fine_condition', 30)->nullable()->after('return_reason');
            $table->index('fine_condition', 'rdo_fine_condition_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_debt_obligations', function (Blueprint $table) {
            $table->dropIndex('rdo_fine_condition_idx');
            $table->dropColumn('fine_condition');
        });
    }
};
