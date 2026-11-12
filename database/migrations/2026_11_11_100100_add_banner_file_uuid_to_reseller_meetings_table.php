<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive optional banner image for reseller meetings.
 *
 * Banner visibility ends at scheduled_at; cancelled meetings are excluded in app code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reseller_meetings')) {
            return;
        }

        Schema::table('reseller_meetings', function (Blueprint $table): void {
            if (! Schema::hasColumn('reseller_meetings', 'banner_file_uuid')) {
                $table->string('banner_file_uuid', 10)->nullable()->after('location');
            }
        });

        Schema::table('reseller_meetings', function (Blueprint $table): void {
            if (! $this->indexExists('reseller_meetings', 'reseller_meetings_banner_file_idx')) {
                $table->index('banner_file_uuid', 'reseller_meetings_banner_file_idx');
            }
        });

        if (Schema::hasTable('files')) {
            Schema::table('reseller_meetings', function (Blueprint $table): void {
                if (! $this->foreignExists('reseller_meetings', 'reseller_meetings_banner_file_fk')) {
                    $table->foreign('banner_file_uuid', 'reseller_meetings_banner_file_fk')
                        ->references('uuid')->on('files')
                        ->restrictOnDelete()
                        ->cascadeOnUpdate();
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('reseller_meetings')) {
            return;
        }

        Schema::table('reseller_meetings', function (Blueprint $table): void {
            if ($this->foreignExists('reseller_meetings', 'reseller_meetings_banner_file_fk')) {
                $table->dropForeign('reseller_meetings_banner_file_fk');
            }

            if ($this->indexExists('reseller_meetings', 'reseller_meetings_banner_file_idx')) {
                $table->dropIndex('reseller_meetings_banner_file_idx');
            }

            if (Schema::hasColumn('reseller_meetings', 'banner_file_uuid')) {
                $table->dropColumn('banner_file_uuid');
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
