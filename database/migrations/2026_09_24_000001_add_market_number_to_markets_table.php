<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->unsignedTinyInteger('market_number')->nullable()->after('code');
            $table->unique('market_number');
        });

        $assignments = [
            'lk' => 1,
            'my' => 2,
        ];

        foreach ($assignments as $code => $number) {
            DB::table('markets')
                ->where('code', $code)
                ->update(['market_number' => $number]);
        }
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropUnique(['market_number']);
            $table->dropColumn('market_number');
        });
    }
};
