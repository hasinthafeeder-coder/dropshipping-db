<?php

use Database\Seeders\NonDeliveryFineDefaultsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds LKR (lk) non-delivery fine defaults into settings.
 * MYR/other markets are intentionally left unconfigured (configuration gap).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NonDeliveryFineDefaultsSeeder())->run();
    }

    public function down(): void
    {
        // Settings rows are retained; clearing them would erase admin customisation.
    }
};
