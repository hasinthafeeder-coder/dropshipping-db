<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-reseller bypass for the monthly 1,000 own-order introducer target.
 * The exception does not change the bonus rate or the direct-team formula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'introducer_bonus_target_exception')) {
                $table->boolean('introducer_bonus_target_exception')->default(false);
            }

            if (! Schema::hasColumn('users', 'introducer_bonus_target_exception_updated_by')) {
                $table->unsignedBigInteger('introducer_bonus_target_exception_updated_by')->nullable();
            }

            if (! Schema::hasColumn('users', 'introducer_bonus_target_exception_updated_at')) {
                $table->timestamp('introducer_bonus_target_exception_updated_at')->nullable();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign(
                'introducer_bonus_target_exception_updated_by',
                'users_ib_exception_updated_by_fk'
            )
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_ib_exception_updated_by_fk');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'introducer_bonus_target_exception',
                'introducer_bonus_target_exception_updated_by',
                'introducer_bonus_target_exception_updated_at',
            ]);
        });
    }
};
