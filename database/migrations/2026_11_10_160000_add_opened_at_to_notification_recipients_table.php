<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive engagement timestamp for in-app notification detail views.
 *
 * delivered  — notification_deliveries.status/delivered_at (already recorded on publish)
 * read/seen  — notification_recipients.read_at (already recorded)
 * opened     — notification_recipients.opened_at (detail page opened)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_recipients')) {
            return;
        }

        if (! Schema::hasColumn('notification_recipients', 'opened_at')) {
            Schema::table('notification_recipients', function (Blueprint $table) {
                $table->timestamp('opened_at')->nullable()->after('read_at');
                $table->index('opened_at', 'notification_recipients_opened_at_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_recipients')) {
            return;
        }

        if (Schema::hasColumn('notification_recipients', 'opened_at')) {
            Schema::table('notification_recipients', function (Blueprint $table) {
                $table->dropIndex('notification_recipients_opened_at_idx');
                $table->dropColumn('opened_at');
            });
        }
    }
};
