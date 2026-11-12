<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive pending-change support for supplier product edit reviews.
 *
 * - products.pending_changes holds unpublished edit payloads while live
 *   approved catalog data remains intact for reseller availability.
 * - product_approval_logs gains submission_type + change_payload so each
 *   review decision keeps an immutable history of what was submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('pending_changes')->nullable()->after('rejection_reason');
        });

        Schema::table('product_approval_logs', function (Blueprint $table) {
            $table->string('submission_type', 20)->nullable()->after('action');
            $table->json('change_payload')->nullable()->after('remarks');

            $table->index('submission_type');
        });
    }

    public function down(): void
    {
        Schema::table('product_approval_logs', function (Blueprint $table) {
            $table->dropIndex(['submission_type']);
            $table->dropColumn(['submission_type', 'change_payload']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pending_changes');
        });
    }
};
