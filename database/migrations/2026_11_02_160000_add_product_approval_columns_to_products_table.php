<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive product approval lifecycle fields.
 *
 * Existing ACTIVE products are grandfathered as APPROVED so historical
 * availability is unchanged. Draft / inactive products remain unsubmitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('approval_status', 30)->nullable()->after('status');
            $table->timestamp('submitted_for_review_at')->nullable()->after('published_at');
            $table->timestamp('approved_at')->nullable()->after('submitted_for_review_at');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('rejected_at');
            $table->text('rejection_reason')->nullable()->after('rejected_by');

            $table->index('approval_status');
            $table->index('submitted_for_review_at');

            $table->foreign('approved_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('rejected_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        DB::table('products')
            ->where('status', 'ACTIVE')
            ->whereNull('approval_status')
            ->update([
                'approval_status' => 'APPROVED',
                'approved_at' => DB::raw('COALESCE(published_at, created_at, NOW())'),
            ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropIndex(['approval_status']);
            $table->dropIndex(['submitted_for_review_at']);
            $table->dropColumn([
                'approval_status',
                'submitted_for_review_at',
                'approved_at',
                'approved_by',
                'rejected_at',
                'rejected_by',
                'rejection_reason',
            ]);
        });
    }
};
