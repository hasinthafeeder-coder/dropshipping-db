<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive link from meeting notices to reseller_meetings.
 *
 * Existing notification rows remain valid with meeting_id null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('reseller_meetings')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'meeting_id')) {
                $table->unsignedBigInteger('meeting_id')->nullable()->after('publisher_company_id');
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            if (! $this->indexExists('notifications', 'notifications_meeting_id_idx')) {
                $table->index('meeting_id', 'notifications_meeting_id_idx');
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            if (! $this->foreignExists('notifications', 'notifications_meeting_fk')) {
                $table->foreign('meeting_id', 'notifications_meeting_fk')
                    ->references('id')->on('reseller_meetings')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if ($this->foreignExists('notifications', 'notifications_meeting_fk')) {
                $table->dropForeign('notifications_meeting_fk');
            }

            if ($this->indexExists('notifications', 'notifications_meeting_id_idx')) {
                $table->dropIndex('notifications_meeting_id_idx');
            }

            if (Schema::hasColumn('notifications', 'meeting_id')) {
                $table->dropColumn('meeting_id');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $meta) {
            if (($meta['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    private function foreignExists(string $table, string $foreign): bool
    {
        foreach (Schema::getForeignKeys($table) as $meta) {
            if (($meta['name'] ?? null) === $foreign) {
                return true;
            }
        }

        return false;
    }
};
