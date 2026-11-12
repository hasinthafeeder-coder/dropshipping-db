<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive optional YouTube video URL and warranty configuration on products.
 *
 * Existing products remain without video/warranty behaviour (nullable URL,
 * warranty_available defaults to false, warranty_period nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('youtube_url', 500)->nullable()->after('guideline_file_id');
            $table->boolean('warranty_available')->default(false)->after('youtube_url');
            $table->string('warranty_period', 100)->nullable()->after('warranty_available');

            $table->index('warranty_available');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['warranty_available']);
            $table->dropColumn([
                'youtube_url',
                'warranty_available',
                'warranty_period',
            ]);
        });
    }
};
