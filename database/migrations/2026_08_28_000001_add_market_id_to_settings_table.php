<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('market_id')
                ->nullable()
                ->after('key')
                ->constrained('markets')
                ->nullOnDelete();

            $table->dropUnique(['key']);
            $table->unique(['key', 'market_id']);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['key', 'market_id']);
            $table->unique(['key']);

            $table->dropConstrainedForeignId('market_id');
        });
    }
};
