<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O1.4-B — Call Center Agent profile schema foundation.
 *
 * - Adds user_profiles.agent_commission_per_order (current rate configuration only).
 * - Makes user_profiles.nic nullable so employee/agent profiles may omit NIC.
 *
 * Future Agent Create (not implemented here) will still require a unique users.email
 * and should supply a system-generated unique email; do not invent that format here.
 *
 * Rollback note: restoring nic to NOT NULL fails if any NULL nic rows exist.
 * Fake NIC values must never be invented to force rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->decimal('agent_commission_per_order', 15, 2)
                ->nullable()
                ->after('profile_photo_uuid')
                ->comment('Current Call Center Agent commission per eligible delivered order.');
        });

        Schema::table('user_profiles', function (Blueprint $table): void {
            // Preserve existing unique index on nic; only relax NOT NULL.
            $table->string('nic', 12)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('user_profiles')->whereNull('nic')->exists()) {
            throw new \RuntimeException(
                'Cannot restore user_profiles.nic to NOT NULL while NULL NIC values exist. '
                .'Remove or populate those profiles before rolling back. '
                .'Fake NIC values must not be invented.'
            );
        }

        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn('agent_commission_per_order');
        });

        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('nic', 12)->nullable(false)->change();
        });
    }
};
