<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {

            $table->id();

            $table->string('uuid', 10)->unique();

            $table->string('application', 20);

            $table->string('entity_type', 30);

            $table->string('entity_uuid', 10);

            $table->string('category', 30);

            $table->string('disk', 30)->default('feeder');

            $table->string('path', 500);

            $table->string('original_name', 255);

            $table->string('extension', 20);

            $table->string('mime_type', 100);

            $table->unsignedBigInteger('size');

            $table->string('checksum', 64)->nullable();

            $table->string('visibility', 20)->default('PRIVATE');

            $table->string('status', 20)->default('ACTIVE');

            $table->json('metadata')->nullable();

            $table->string('uploaded_by', 10)->nullable();

            $table->timestamps();

            $table->softDeletes();

            $table->index('application');
            $table->index('entity_type');
            $table->index('entity_uuid');
            $table->index('category');
            $table->index('status');
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
