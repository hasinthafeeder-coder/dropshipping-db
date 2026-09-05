<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('supplier_id');
            $table->uuid('category_id');
            $table->string('name');
            $table->string('slug');
            $table->unsignedBigInteger('guideline_file_id')->nullable();
            $table->boolean('system_visible')->default(true);
            $table->boolean('web_visible')->default(true);
            $table->boolean('price_locked')->default(false);
            $table->string('status', 30)->default('DRAFT');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('category_id')
                ->references('id')
                ->on('product_categories')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('supplier_id');
            $table->index('category_id');
            $table->index('status');
            $table->index('published_at');
            $table->index('system_visible');
            $table->index('web_visible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
