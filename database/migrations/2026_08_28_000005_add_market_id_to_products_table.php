<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('market_id')
                ->nullable()
                ->after('category_id')
                ->constrained('markets')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('market_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['market_id']);
            $table->dropColumn('market_id');
        });
    }
};
