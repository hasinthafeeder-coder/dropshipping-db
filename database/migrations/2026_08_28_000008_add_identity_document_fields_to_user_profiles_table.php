<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('identity_document_type', 30)
                ->nullable()
                ->after('nic');
            $table->string('identity_document_number', 50)
                ->nullable()
                ->after('identity_document_type');
        });

        DB::table('user_profiles')
            ->whereNotNull('nic')
            ->where('nic', '!=', '')
            ->update([
                'identity_document_type' => 'NIC',
                'identity_document_number' => DB::raw('nic'),
            ]);

        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->unique('identity_document_number');
            $table->index('identity_document_type');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropUnique(['identity_document_number']);
            $table->dropIndex(['identity_document_type']);
            $table->dropColumn(['identity_document_type', 'identity_document_number']);
        });
    }
};
