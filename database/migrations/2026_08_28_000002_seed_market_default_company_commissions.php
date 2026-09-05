<?php

use Database\Seeders\MarketDefaultCompanyCommissionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new MarketDefaultCompanyCommissionSeeder())->run();
    }

    public function down(): void
    {
        // Market defaults are retained on rollback to avoid recalculating product variant commissions.
    }
};
