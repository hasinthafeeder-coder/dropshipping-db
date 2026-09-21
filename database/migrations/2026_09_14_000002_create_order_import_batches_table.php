<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('reseller_company_id');
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('uploaded_by');
            $table->string('original_filename');
            $table->string('file_type', 20);
            $table->string('stored_path')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('banned_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->string('status', 30);
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('reseller_company_id')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('uploaded_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['reseller_company_id', 'status']);
            $table->index('uploaded_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_import_batches');
    }
};
