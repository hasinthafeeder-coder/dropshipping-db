<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Currency-scoped bank workbook attempts, immutable line snapshots, and exclusions.
 *
 * ready_version is NULL on FAILED so failed attempts do not consume a READY version.
 * "One non-superseded READY per batch/currency" is enforced in services until
 * production VERSION() confirms engine-specific unique DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_settlement_bank_exports', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->string('purpose', 20);
            $table->unsignedInteger('attempt_number');
            $table->unsignedInteger('ready_version')->nullable();
            $table->string('status', 20);
            $table->date('value_date');
            $table->boolean('value_date_overridden')->default(false);
            $table->unsignedBigInteger('value_date_overridden_by')->nullable();
            $table->text('value_date_override_reason')->nullable();
            $table->string('file_uuid', 10)->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_user_id')->nullable();
            $table->text('supersede_reason')->nullable();
            $table->string('previous_file_disposition', 30)->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('first_downloaded_at')->nullable();
            $table->unsignedBigInteger('first_downloaded_by')->nullable();
            $table->timestamp('last_downloaded_at')->nullable();
            $table->unsignedBigInteger('last_downloaded_by')->nullable();
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(
                ['batch_id', 'currency_id', 'attempt_number'],
                'ssbe_batch_currency_attempt_unique'
            );
            $table->unique(
                ['batch_id', 'currency_id', 'ready_version'],
                'ssbe_batch_currency_ready_version_unique'
            );

            $table->foreign('batch_id', 'ssbe_batch_fk')
                ->references('id')
                ->on('supplier_settlement_batches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'ssbe_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('value_date_overridden_by', 'ssbe_value_date_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('superseded_by_user_id', 'ssbe_superseded_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('first_downloaded_by', 'ssbe_first_dl_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('last_downloaded_by', 'ssbe_last_dl_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('generated_by', 'ssbe_generated_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index('status', 'ssbe_status_idx');
            $table->index('file_uuid', 'ssbe_file_uuid_idx');
            $table->index('generated_at', 'ssbe_generated_at_idx');
            $table->index(['batch_id', 'currency_id', 'status'], 'ssbe_batch_currency_status_idx');
        });

        Schema::create('supplier_settlement_bank_export_lines', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('bank_export_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('supplier_company_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            // Origin fields are optional: Feeder does not hold or transfer funds.
            // Workbook columns remain for format compatibility and stay blank.
            $table->unsignedBigInteger('origin_company_bank_account_id')->nullable();
            $table->unsignedBigInteger('beneficiary_company_bank_account_id');
            $table->string('origin_account_number', 15)->nullable();
            $table->string('origin_account_name', 150)->nullable();
            $table->string('value_date_text', 10);
            $table->string('beneficiary_bank_code', 30);
            $table->string('beneficiary_branch_code', 30);
            $table->string('beneficiary_account_number', 50);
            $table->string('beneficiary_account_name', 150);
            $table->decimal('amount', 15, 2);
            $table->string('particulars', 100);
            $table->string('reference_text', 100);
            $table->unsignedInteger('row_number');
            $table->timestamps();

            $table->unique(['bank_export_id', 'invoice_id'], 'ssbel_export_invoice_unique');
            $table->unique(['bank_export_id', 'row_number'], 'ssbel_export_row_unique');

            $table->foreign('bank_export_id', 'ssbel_export_fk')
                ->references('id')
                ->on('supplier_settlement_bank_exports')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('invoice_id', 'ssbel_invoice_fk')
                ->references('id')
                ->on('supplier_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_company_id', 'ssbel_supplier_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'ssbel_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('origin_company_bank_account_id', 'ssbel_origin_acct_fk')
                ->references('id')
                ->on('company_bank_accounts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('beneficiary_company_bank_account_id', 'ssbel_benef_acct_fk')
                ->references('id')
                ->on('company_bank_accounts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::create('supplier_settlement_bank_export_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('bank_export_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('supplier_company_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('reason_code', 40);
            $table->string('reason_detail', 255);
            $table->timestamps();

            $table->unique(
                ['bank_export_id', 'invoice_id', 'reason_code'],
                'ssbee_export_invoice_reason_unique'
            );

            $table->foreign('bank_export_id', 'ssbee_export_fk')
                ->references('id')
                ->on('supplier_settlement_bank_exports')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('invoice_id', 'ssbee_invoice_fk')
                ->references('id')
                ->on('supplier_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_company_id', 'ssbee_supplier_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'ssbee_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index('reason_code', 'ssbee_reason_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_settlement_bank_export_exceptions');
        Schema::dropIfExists('supplier_settlement_bank_export_lines');
        Schema::dropIfExists('supplier_settlement_bank_exports');
    }
};
