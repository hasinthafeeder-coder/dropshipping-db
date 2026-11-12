<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive supplier notification context.
 *
 * product_id links a supplier-managed notice to an owned product.
 * publisher_company_id scopes ownership to the authenticated supplier company.
 * Existing admin rows remain valid with both columns null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'product_id')) {
                $table->unsignedBigInteger('product_id')->nullable()->after('created_by');
            }

            if (! Schema::hasColumn('notifications', 'publisher_company_id')) {
                $table->unsignedBigInteger('publisher_company_id')->nullable()->after('product_id');
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            if (! $this->indexExists('notifications', 'notifications_product_id_idx')) {
                $table->index('product_id', 'notifications_product_id_idx');
            }

            if (! $this->indexExists('notifications', 'notifications_publisher_company_idx')) {
                $table->index('publisher_company_id', 'notifications_publisher_company_idx');
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            if (! $this->foreignExists('notifications', 'notifications_product_fk')) {
                $table->foreign('product_id', 'notifications_product_fk')
                    ->references('id')->on('products')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            }

            if (! $this->foreignExists('notifications', 'notifications_publisher_company_fk')) {
                $table->foreign('publisher_company_id', 'notifications_publisher_company_fk')
                    ->references('id')->on('companies')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if ($this->foreignExists('notifications', 'notifications_product_fk')) {
                $table->dropForeign('notifications_product_fk');
            }

            if ($this->foreignExists('notifications', 'notifications_publisher_company_fk')) {
                $table->dropForeign('notifications_publisher_company_fk');
            }

            if ($this->indexExists('notifications', 'notifications_product_id_idx')) {
                $table->dropIndex('notifications_product_id_idx');
            }

            if ($this->indexExists('notifications', 'notifications_publisher_company_idx')) {
                $table->dropIndex('notifications_publisher_company_idx');
            }

            if (Schema::hasColumn('notifications', 'product_id')) {
                $table->dropColumn('product_id');
            }

            if (Schema::hasColumn('notifications', 'publisher_company_id')) {
                $table->dropColumn('publisher_company_id');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $meta) {
            if (($meta['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    private function foreignExists(string $table, string $foreign): bool
    {
        foreach (Schema::getForeignKeys($table) as $meta) {
            if (($meta['name'] ?? null) === $foreign) {
                return true;
            }
        }

        return false;
    }
};
