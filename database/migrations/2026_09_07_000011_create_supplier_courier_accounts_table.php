<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_courier_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('courier_id');
            $table->string('account_label');
            $table->text('credentials_encrypted');
            $table->json('meta_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_id')
                ->references('id')
                ->on('couriers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['supplier_id', 'courier_id']);
            $table->index('courier_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_courier_accounts');
    }
};
