<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('operation_market_id')
                ->nullable()
                ->after('portal_id')
                ->constrained('markets')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreignId('home_country_id')
                ->nullable()
                ->after('operation_market_id')
                ->constrained('countries')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('operation_market_id');
            $table->index('home_country_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['operation_market_id']);
            $table->dropForeign(['home_country_id']);
            $table->dropColumn(['operation_market_id', 'home_country_id']);
        });
    }
};
