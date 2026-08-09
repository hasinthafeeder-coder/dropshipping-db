<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('customer_care_phone', 20)->nullable()->after('phone');
            $table->uuid('logo_uuid')->nullable()->after('tax_number');
            $table->uuid('business_reg_pdf_uuid')->nullable()->after('logo_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['customer_care_phone', 'logo_uuid', 'business_reg_pdf_uuid']);
        });
    }
};
