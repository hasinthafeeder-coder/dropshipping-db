<?php

use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\SupplierType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('supplier_type', 20)
                ->default(SupplierType::STANDARD->value)
                ->after('status');
        });

        $supplierPortalId = DB::table('portals')
            ->where('code', PortalCode::SUPPLIER->value)
            ->value('id');

        if (! $supplierPortalId) {
            return;
        }

        $companyIds = DB::table('companies')
            ->where('portal_id', $supplierPortalId)
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($companyIds as $companyId) {
            $supplierType = (crc32((string) $companyId) % 3 === 0)
                ? SupplierType::PRO->value
                : SupplierType::STANDARD->value;

            DB::table('companies')
                ->where('id', $companyId)
                ->update(['supplier_type' => $supplierType]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('supplier_type');
        });
    }
};
