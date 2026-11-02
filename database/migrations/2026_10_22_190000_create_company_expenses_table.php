<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company expense ledger.
 *
 * Posted rows are kept permanently. Voiding sets status, actor, time, and
 * reason; it does not remove the row or change the original amount.
 * No expense records are inserted by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('expense_reference', 40)->unique();
            $table->date('expense_date');
            $table->string('category', 40);
            $table->text('description');
            $table->string('payee', 200)->nullable();
            $table->unsignedBigInteger('currency_id');
            $table->char('currency_code', 3);
            $table->decimal('amount', 15, 2);
            $table->string('supporting_document_file_uuid', 10)->nullable();
            $table->string('supporting_document_original_name', 255)->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('corrects_expense_id')->nullable();
            $table->timestamps();

            $table->unique('corrects_expense_id', 'company_expenses_one_correction_unique');
            $table->index('expense_date');
            $table->index('category');
            $table->index('currency_code');
            $table->index('status');
            $table->index('created_by');

            $table->foreign('currency_id', 'company_expenses_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('created_by', 'company_expenses_created_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('voided_by', 'company_expenses_voided_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('corrects_expense_id', 'company_expenses_corrects_fk')
                ->references('id')
                ->on('company_expenses')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supporting_document_file_uuid', 'company_expenses_document_fk')
                ->references('uuid')
                ->on('files')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_expenses');
    }
};
