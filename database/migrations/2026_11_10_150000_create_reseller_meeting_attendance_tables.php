<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Monthly reseller meeting attendance + QR check-in.
 *
 * Additive only. Does not alter historical company columns beyond relying on
 * existing companies.approved_at as the membership/eligibility timestamp.
 *
 * active_month_key is YYYY-MM while status is SCHEDULED, and null when
 * CANCELLED, so at most one active meeting exists per calendar month.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reseller_meetings')) {
            Schema::create('reseller_meetings', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 10)->unique();
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->timestamp('scheduled_at');
                $table->string('location', 255)->nullable();
                $table->char('calendar_month', 7);
                $table->string('active_month_key', 7)->nullable();
                $table->string('status', 20);
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('cancel_reason', 500)->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique('active_month_key', 'reseller_meetings_active_month_key_unique');
                $table->index(['status', 'scheduled_at'], 'reseller_meetings_status_scheduled_idx');
                $table->index('calendar_month', 'reseller_meetings_calendar_month_idx');
                $table->index('created_by', 'reseller_meetings_created_by_idx');

                $table->foreign('created_by', 'reseller_meetings_created_by_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('updated_by', 'reseller_meetings_updated_by_fk')
                    ->references('id')->on('users')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('cancelled_by', 'reseller_meetings_cancelled_by_fk')
                    ->references('id')->on('users')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('reseller_meeting_check_in_tokens')) {
            Schema::create('reseller_meeting_check_in_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 10)->unique();
                $table->unsignedBigInteger('meeting_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->string('token', 64)->unique();
                $table->timestamp('issued_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['meeting_id', 'company_id'],
                    'reseller_meeting_tokens_meeting_company_unique'
                );
                $table->index(
                    ['meeting_id', 'user_id'],
                    'reseller_meeting_tokens_meeting_user_idx'
                );

                $table->foreign('meeting_id', 'reseller_meeting_tokens_meeting_fk')
                    ->references('id')->on('reseller_meetings')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('company_id', 'reseller_meeting_tokens_company_fk')
                    ->references('id')->on('companies')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('user_id', 'reseller_meeting_tokens_user_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('reseller_meeting_attendances')) {
            Schema::create('reseller_meeting_attendances', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 10)->unique();
                $table->unsignedBigInteger('meeting_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('checked_in_at');
                $table->string('check_in_method', 20);
                $table->unsignedBigInteger('checked_in_by');
                $table->unsignedBigInteger('token_id')->nullable();
                $table->timestamps();

                $table->unique(
                    ['meeting_id', 'company_id'],
                    'reseller_meeting_attendances_meeting_company_unique'
                );
                $table->index(
                    ['meeting_id', 'checked_in_at'],
                    'reseller_meeting_attendances_meeting_checked_idx'
                );

                $table->foreign('meeting_id', 'reseller_meeting_attendances_meeting_fk')
                    ->references('id')->on('reseller_meetings')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('company_id', 'reseller_meeting_attendances_company_fk')
                    ->references('id')->on('companies')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('user_id', 'reseller_meeting_attendances_user_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('checked_in_by', 'reseller_meeting_attendances_staff_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('token_id', 'reseller_meeting_attendances_token_fk')
                    ->references('id')->on('reseller_meeting_check_in_tokens')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('reseller_meeting_excuses')) {
            Schema::create('reseller_meeting_excuses', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 10)->unique();
                $table->unsignedBigInteger('meeting_id');
                $table->unsignedBigInteger('company_id');
                $table->text('reason');
                $table->string('status', 20);
                $table->unsignedBigInteger('approved_by');
                $table->timestamp('approved_at');
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->unique(
                    ['meeting_id', 'company_id'],
                    'reseller_meeting_excuses_meeting_company_unique'
                );
                $table->index(
                    ['company_id', 'status'],
                    'reseller_meeting_excuses_company_status_idx'
                );

                $table->foreign('meeting_id', 'reseller_meeting_excuses_meeting_fk')
                    ->references('id')->on('reseller_meetings')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('company_id', 'reseller_meeting_excuses_company_fk')
                    ->references('id')->on('companies')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('approved_by', 'reseller_meeting_excuses_approved_by_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('created_by', 'reseller_meeting_excuses_created_by_fk')
                    ->references('id')->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_meeting_excuses');
        Schema::dropIfExists('reseller_meeting_attendances');
        Schema::dropIfExists('reseller_meeting_check_in_tokens');
        Schema::dropIfExists('reseller_meetings');
    }
};
