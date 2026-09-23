<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link bank-transfer payment proofs to feeder-files (files.uuid).
 * Keeps slip_path for backward compatibility with any existing local slips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payment_submissions', function (Blueprint $table) {
            $table->string('slip_file_uuid', 10)
                ->nullable()
                ->after('slip_path');

            $table->index('slip_file_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('order_payment_submissions', function (Blueprint $table) {
            $table->dropIndex(['slip_file_uuid']);
            $table->dropColumn('slip_file_uuid');
        });
    }
};
