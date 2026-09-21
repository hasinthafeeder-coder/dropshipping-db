<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_cities', function (Blueprint $table) {
            $table->unsignedBigInteger('courier_state_id')->nullable()->after('courier_id');
            $table->string('external_id')->nullable()->after('courier_state_id');
            $table->string('external_ref_no')->nullable()->after('external_id');
            $table->string('name')->nullable()->after('external_ref_no');
            $table->string('postal_code')->nullable()->after('name');
            $table->timestamp('last_synced_at')->nullable()->after('is_active');

            $table->foreign('courier_state_id')
                ->references('id')
                ->on('courier_states')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['courier_id', 'external_id']);
            $table->index('courier_state_id');
        });
    }

    public function down(): void
    {
        Schema::table('courier_cities', function (Blueprint $table) {
            $table->dropUnique(['courier_id', 'external_id']);
            $table->dropIndex(['courier_state_id']);
            $table->dropForeign(['courier_state_id']);

            $table->dropColumn([
                'courier_state_id',
                'external_id',
                'external_ref_no',
                'name',
                'postal_code',
                'last_synced_at',
            ]);
        });
    }
};
