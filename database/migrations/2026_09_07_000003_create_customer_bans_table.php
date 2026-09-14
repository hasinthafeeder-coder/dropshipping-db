<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_bans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_id');
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('banned_by_user_id');
            $table->unsignedBigInteger('banned_by_company_id')->nullable();
            $table->timestamp('banned_at');
            $table->unsignedBigInteger('lifted_by_user_id')->nullable();
            $table->unsignedBigInteger('lifted_by_company_id')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('banned_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('banned_by_company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('lifted_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('lifted_by_company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('customer_id');
            $table->index('status');
            $table->index('banned_at');
        });

        // MySQL/InnoDB rejects STORED generated columns that reference FK columns.
        // Functional unique index preserves the same one-ACTIVE-ban invariant:
        // active marker = customer_id when status = ACTIVE, otherwise NULL.
        DB::statement("
            CREATE UNIQUE INDEX customer_bans_active_customer_unique
            ON customer_bans ((CASE WHEN `status` = 'ACTIVE' THEN `customer_id` END))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_bans');
    }
};
