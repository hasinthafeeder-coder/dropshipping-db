<?php

use Database\Support\SriLankaMarketBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SriLankaMarketBackfill::run();
    }

    public function down(): void
    {
        // Backfill data is not reversed to preserve existing assignments.
    }
};
