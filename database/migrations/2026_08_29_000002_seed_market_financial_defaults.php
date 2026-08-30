<?php

use Database\Seeders\MarketFinancialDefaultsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new MarketFinancialDefaultsSeeder())->run();
    }

    public function down(): void
    {
        // Market defaults are retained on rollback to avoid recalculating reseller overrides.
    }
};
