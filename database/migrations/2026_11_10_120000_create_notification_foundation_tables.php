<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared in-app notification foundation.
 *
 * notifications          — channel-independent content + schedule + optional banner
 * notification_recipients — targeted users, company isolation, read status
 * notification_deliveries — per-channel delivery rows (IN_APP in phase 1)
 *
 * Additive only. No existing tables or business data are altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 10)->unique();
                $table->string('type', 40);
                $table->string('title', 200);
                $table->text('body');
                $table->string('banner_file_uuid', 10)->nullable();
                $table->string('audience_type', 40);
                $table->timestamp('publish_at');
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->string('source_event_type', 80)->nullable();
                $table->string('source_event_key', 80)->nullable();
                $table->timestamps();

                $table->unique('source_event_key', 'notifications_source_event_key_unique');
                $table->index(['type', 'publish_at'], 'notifications_type_publish_idx');
                $table->index(['publish_at', 'expires_at'], 'notifications_schedule_idx');
                $table->index('created_by', 'notifications_created_by_idx');

                $table->foreign('created_by', 'notifications_created_by_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->foreign('banner_file_uuid', 'notifications_banner_file_fk')
                    ->references('uuid')->on('files')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('notification_recipients')) {
            Schema::create('notification_recipients', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 10)->unique();
                $table->unsignedBigInteger('notification_id');
                $table->unsignedBigInteger('recipient_user_id');
                $table->unsignedBigInteger('recipient_company_id');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['notification_id', 'recipient_user_id'],
                    'notification_recipients_notification_user_unique'
                );
                $table->index(
                    ['recipient_user_id', 'recipient_company_id'],
                    'notification_recipients_user_company_idx'
                );
                $table->index('read_at', 'notification_recipients_read_at_idx');

                $table->foreign('notification_id', 'notification_recipients_notification_fk')
                    ->references('id')->on('notifications')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();

                $table->foreign('recipient_user_id', 'notification_recipients_user_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->foreign('recipient_company_id', 'notification_recipients_company_fk')
                    ->references('id')->on('companies')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 10)->unique();
                $table->unsignedBigInteger('notification_recipient_id');
                $table->string('channel', 20);
                $table->string('status', 20);
                $table->timestamp('delivered_at')->nullable();
                $table->string('failure_reason', 500)->nullable();
                $table->timestamps();

                $table->unique(
                    ['notification_recipient_id', 'channel'],
                    'notification_deliveries_recipient_channel_unique'
                );
                $table->index(['channel', 'status'], 'notification_deliveries_channel_status_idx');

                $table->foreign('notification_recipient_id', 'notification_deliveries_recipient_fk')
                    ->references('id')->on('notification_recipients')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notifications');
    }
};
