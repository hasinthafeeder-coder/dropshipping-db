<?php

namespace Database\Seeders;

use Database\Support\SriLankaMarketBackfill;
use Illuminate\Database\Seeder;

class SriLankaMarketBackfillSeeder extends Seeder
{
    public function run(): void
    {
        SriLankaMarketBackfill::run();
    }
}
