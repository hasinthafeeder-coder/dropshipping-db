<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist courier receipt import confirmation actor + outcome counts.
 *
 * confirmed_at already exists. Preview match counts remain separate columns;
 * confirmation delivered/skipped/ineligible counts are stored in confirmation_summary
 * so historical outcomes stay accurate after live order statuses change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('courier_receipt_imports')) {
            return;
        }

        Schema::table('courier_receipt_imports', function (Blueprint $table) {
            if (! Schema::hasColumn('courier_receipt_imports', 'confirmed_by')) {
                $table->unsignedBigInteger('confirmed_by')
                    ->nullable()
                    ->after('confirmed_at');

                $table->foreign('confirmed_by', 'cri_confirmed_by_fk')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $table->index('confirmed_by', 'cri_confirmed_by_idx');
            }

            if (! Schema::hasColumn('courier_receipt_imports', 'confirmation_summary')) {
                $table->json('confirmation_summary')
                    ->nullable()
                    ->after('confirmed_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('courier_receipt_imports')) {
            return;
        }

        Schema::table('courier_receipt_imports', function (Blueprint $table) {
            if (Schema::hasColumn('courier_receipt_imports', 'confirmed_by')) {
                $table->dropForeign('cri_confirmed_by_fk');
                $table->dropIndex('cri_confirmed_by_idx');
                $table->dropColumn('confirmed_by');
            }

            if (Schema::hasColumn('courier_receipt_imports', 'confirmation_summary')) {
                $table->dropColumn('confirmation_summary');
            }
        });
    }
};
