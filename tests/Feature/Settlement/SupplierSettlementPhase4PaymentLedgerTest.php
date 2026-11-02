<?php

namespace Tests\Feature\Settlement;

use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\SupplierPayment;
use Feeder\Core\Models\SupplierSettlementAccountDesignation;
use Feeder\Core\Models\SupplierSettlementBankExport;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\SupplierSettlementReturnedReference;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Services\Settlement\SupplierSettlementBankExportService;
use Feeder\Core\Services\Settlement\SupplierSettlementNumberGenerator;
use Feeder\Core\Services\Settlement\SupplierSettlementPaymentService;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsSupplierSettlementPhase4Schema;
use Tests\TestCase;

class SupplierSettlementPhase4PaymentLedgerTest extends TestCase
{
    use BuildsSupplierSettlementPhase4Schema;

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureIsolatedSettlementPhase4Database();
        config([
            'feeder.markets.timezones' => [
                'lk' => 'Asia/Colombo',
                'my' => 'Asia/Kuala_Lumpur',
            ],
        ]);

        Storage::fake('local');

        if (! self::$schemaReady) {
            $this->buildSupplierSettlementPhase4Schema();
            self::$schemaReady = true;
        } else {
            $this->truncateSettlementTables();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$schemaReady = false;

        parent::tearDownAfterClass();
    }

    public function test_successful_full_payment_against_locked_invoice(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $payment = $this->recordPayment($fixture);

        $this->assertSame(SupplierPayment::ENTRY_TYPE_PAYMENT, $payment->entry_type);
        $this->assertSame('100.00', (string) $payment->amount);
        $this->assertSame('LKR', $payment->currency_code_snapshot);
        $this->assertSame((int) $fixture['invoiceId'], (int) $payment->invoice_id);
        $this->assertSame((int) $fixture['adminId'], (int) $payment->confirmed_by);
        $this->assertNull($payment->reverses_payment_id);

        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $this->assertSame((int) $payment->id, (int) $invoice->effective_payment_id);
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_PAID, $invoice->payment_status);
        $this->assertSame(1, DB::table('supplier_payments')->count());
    }

    public function test_payment_amount_and_currency_must_match_invoice_exactly(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $service = $this->paymentService();
        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $actor = User::query()->findOrFail($fixture['adminId']);

        try {
            $service->record(
                $invoice,
                $actor,
                '99.99',
                (int) $fixture['currencyId'],
                'pay-amount-mismatch',
                true,
            );
            $this->fail('Expected partial/mismatched amount to be rejected.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_PAYMENT_AMOUNT_MISMATCH', $exception->errorCode);
        }

        $otherCurrencyId = DB::table('currencies')->insertGetId(['code' => 'MYR']);

        try {
            $service->record(
                $invoice,
                $actor,
                '100.00',
                $otherCurrencyId,
                'pay-currency-mismatch',
                true,
            );
            $this->fail('Expected currency mismatch to be rejected.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_PAYMENT_CURRENCY_MISMATCH', $exception->errorCode);
        }

        $this->assertSame(0, DB::table('supplier_payments')->count());
        $this->assertNull(
            DB::table('supplier_settlement_invoices')->where('id', $fixture['invoiceId'])->value('effective_payment_id')
        );
    }

    public function test_open_batch_payment_is_rejected(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        DB::table('supplier_settlement_batches')
            ->where('id', $fixture['batchId'])
            ->update(['status' => SupplierSettlementBatch::STATUS_OPEN, 'locked_at' => null, 'locked_by' => null]);

        try {
            $this->recordPayment($fixture, idempotencyKey: 'pay-open-batch');
            $this->fail('Expected OPEN batch payment to be rejected.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_BATCH_NOT_LOCKED', $exception->errorCode);
        }

        $this->assertSame(0, DB::table('supplier_payments')->count());
    }

    public function test_ready_export_without_transfer_confirmation_does_not_create_payment(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $export = $this->prepareExport($fixture);
        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->status);

        $service = $this->paymentService();
        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $actor = User::query()->findOrFail($fixture['adminId']);

        try {
            $service->record(
                $invoice,
                $actor,
                '100.00',
                (int) $fixture['currencyId'],
                'pay-no-confirm',
                false,
                null,
                $export->exportLines->first()->id,
            );
            $this->fail('Expected unconfirmed transfer to be rejected.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_PAYMENT_TRANSFER_NOT_CONFIRMED', $exception->errorCode);
        }

        $this->assertSame(0, DB::table('supplier_payments')->count());
        $invoice->refresh();
        $this->assertNull($invoice->effective_payment_id);
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID, $invoice->payment_status);
        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->fresh()->status);
    }

    public function test_identical_idempotent_request_creates_only_one_payment(): void
    {
        $fixture = $this->seedLockedPayableFixture();

        $first = $this->recordPayment($fixture, idempotencyKey: 'pay-idem-1');
        $second = $this->recordPayment($fixture, idempotencyKey: 'pay-idem-1');

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, DB::table('supplier_payments')->where('entry_type', 'PAYMENT')->count());
        $this->assertSame(1, DB::table('supplier_payments')->count());
    }

    public function test_idempotency_key_reuse_with_conflicting_data_is_rejected(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $this->recordPayment($fixture, idempotencyKey: 'pay-conflict', amount: '100.00');

        $service = $this->paymentService();
        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $actor = User::query()->findOrFail($fixture['adminId']);

        // Force a second unpaid invoice in another locked batch to attempt conflicting reuse.
        $other = $this->seedLockedPayableFixture([
            'batch_number' => 'SSB-LK-20261012-DAILY-2',
            'invoice_suffix' => '2',
            'payable_total' => '100.00',
        ]);

        try {
            $service->record(
                SupplierSettlementInvoice::query()->findOrFail($other['invoiceId']),
                User::query()->findOrFail($other['adminId']),
                '100.00',
                (int) $other['currencyId'],
                'pay-conflict',
                true,
            );
            $this->fail('Expected conflicting idempotency reuse to be rejected.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_PAYMENT_IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }

        $this->assertSame(1, DB::table('supplier_payments')->count());
        $this->assertSame(
            (int) $fixture['invoiceId'],
            (int) DB::table('supplier_payments')->value('invoice_id'),
        );
        unset($invoice, $actor);
    }

    public function test_conditional_effective_payment_update_rolls_back_on_failure(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $first = $this->recordPayment($fixture, idempotencyKey: 'pay-first');

        $service = $this->paymentService();
        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $actor = User::query()->findOrFail($fixture['adminId']);

        try {
            $service->record(
                $invoice,
                $actor,
                '100.00',
                (int) $fixture['currencyId'],
                'pay-second-should-fail',
                true,
            );
            $this->fail('Expected second effective payment attempt to fail.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_INVOICE_ALREADY_PAID', $exception->errorCode);
        }

        $this->assertSame(1, DB::table('supplier_payments')->count());
        $this->assertSame((int) $first->id, (int) DB::table('supplier_payments')->value('id'));
        $this->assertSame(
            (int) $first->id,
            (int) DB::table('supplier_settlement_invoices')->where('id', $fixture['invoiceId'])->value('effective_payment_id'),
        );
    }

    public function test_two_attempts_cannot_create_two_effective_payments_for_one_invoice(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $this->recordPayment($fixture, idempotencyKey: 'pay-a');

        try {
            $this->recordPayment($fixture, idempotencyKey: 'pay-b');
            $this->fail('Expected second payment for same invoice to be rejected.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_INVOICE_ALREADY_PAID', $exception->errorCode);
        }

        $this->assertSame(1, DB::table('supplier_payments')->where('entry_type', 'PAYMENT')->count());
        $this->assertSame(
            1,
            DB::table('supplier_settlement_invoices')
                ->where('id', $fixture['invoiceId'])
                ->whereNotNull('effective_payment_id')
                ->count(),
        );
    }

    public function test_invalid_or_already_reversed_payment_cannot_be_reversed(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $payment = $this->recordPayment($fixture, idempotencyKey: 'pay-to-reverse');
        $reversal = $this->reversePayment($payment, $fixture, idempotencyKey: 'rev-1');

        $this->assertSame(SupplierPayment::ENTRY_TYPE_REVERSAL, $reversal->entry_type);

        try {
            $this->reversePayment($payment, $fixture, idempotencyKey: 'rev-2');
            $this->fail('Expected second reversal to be rejected.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_PAYMENT_ALREADY_REVERSED', $exception->errorCode);
        }

        try {
            $this->reversePayment($reversal, $fixture, idempotencyKey: 'rev-of-reversal');
            $this->fail('Expected reversing a REVERSAL row to be rejected.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_REVERSAL_NOT_A_PAYMENT', $exception->errorCode);
        }

        $this->assertSame(1, DB::table('supplier_payments')->where('entry_type', 'REVERSAL')->count());
    }

    public function test_successful_reversal_appends_reversal_and_preserves_original_payment(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $payment = $this->recordPayment($fixture, idempotencyKey: 'pay-keep');
        $originalSnapshot = DB::table('supplier_payments')->where('id', $payment->id)->first();

        $reversal = $this->reversePayment($payment, $fixture, reason: 'Bank rejected transfer', idempotencyKey: 'rev-keep');

        $this->assertSame(SupplierPayment::ENTRY_TYPE_REVERSAL, $reversal->entry_type);
        $this->assertSame((int) $payment->id, (int) $reversal->reverses_payment_id);
        $this->assertSame('Bank rejected transfer', $reversal->reversal_reason);
        $this->assertSame('100.00', (string) $reversal->amount);

        $after = DB::table('supplier_payments')->where('id', $payment->id)->first();
        $this->assertEquals($originalSnapshot->entry_type, $after->entry_type);
        $this->assertEquals($originalSnapshot->amount, $after->amount);
        $this->assertEquals($originalSnapshot->currency_id, $after->currency_id);
        $this->assertEquals($originalSnapshot->confirmed_by, $after->confirmed_by);
        $this->assertEquals($originalSnapshot->idempotency_key, $after->idempotency_key);
        $this->assertNull($after->reverses_payment_id);
        $this->assertNull($after->reversal_reason);

        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $this->assertNull($invoice->effective_payment_id);
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID, $invoice->payment_status);
    }

    public function test_duplicate_reversal_requests_cannot_create_duplicate_reversal_rows(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $payment = $this->recordPayment($fixture, idempotencyKey: 'pay-dup-rev');

        $first = $this->reversePayment($payment, $fixture, idempotencyKey: 'rev-dup');
        $second = $this->reversePayment($payment, $fixture, idempotencyKey: 'rev-dup');

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, DB::table('supplier_payments')->where('entry_type', 'REVERSAL')->count());
        $this->assertSame(1, DB::table('supplier_payments')->where('reverses_payment_id', $payment->id)->count());
    }

    public function test_valid_reversal_makes_invoice_outstanding_via_schema_fields(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $payment = $this->recordPayment($fixture, idempotencyKey: 'pay-out');
        $this->reversePayment($payment, $fixture, idempotencyKey: 'rev-out');

        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $this->assertNull($invoice->effective_payment_id);
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID, $invoice->payment_status);
        $this->assertSame(SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE, $invoice->instruction_status);

        // Invoice may be paid again after a valid reversal.
        $repay = $this->recordPayment($fixture, idempotencyKey: 'pay-out-2');
        $this->assertSame((int) $repay->id, (int) $invoice->fresh()->effective_payment_id);
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_PAID, $invoice->fresh()->payment_status);
        $this->assertSame(2, DB::table('supplier_payments')->where('entry_type', 'PAYMENT')->count());
        $this->assertSame(1, DB::table('supplier_payments')->where('entry_type', 'REVERSAL')->count());
    }

    public function test_returned_references_cannot_receive_payments(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $productId = DB::table('products')->insertGetId(['name' => 'Returned Widget']);
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'name' => 'Default',
            'cost' => 50.00,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'supplier_id' => $fixture['userId'],
            'currency_id' => $fixture['currencyId'],
            'order_number' => 'ORD-RET-1',
            'status' => 'RETURNED',
        ]);
        $orderItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_name_snapshot' => 'Returned Widget',
            'variant_name_snapshot' => 'Default',
            'quantity' => 1,
            'unit_cost_snapshot' => 50.00,
        ]);

        $returnedId = DB::table('supplier_settlement_returned_references')->insertGetId([
            'uuid' => 'RET'.Str::upper(Str::random(7)),
            'batch_id' => $fixture['batchId'],
            'supplier_company_id' => $fixture['companyId'],
            'currency_id' => $fixture['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'order_number_snapshot' => 'ORD-RET-1',
            'order_status_snapshot' => 'RETURNED',
            'supplier_user_id' => $fixture['userId'],
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_name_snapshot' => 'Returned Widget',
            'variant_name_snapshot' => 'Default',
            'quantity' => 1,
            'unit_cost' => 50.00,
            'display_payable' => 0.00,
            'returned_at_business_date' => '2026-10-12',
            'source' => SupplierSettlementReturnedReference::SOURCE_PRE_LOCK,
            'prior_payable_invoice_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotNull(SupplierSettlementReturnedReference::query()->find($returnedId));
        $this->assertNull(SupplierSettlementInvoice::query()->find($returnedId));
        $this->assertSame(0, DB::table('supplier_payments')->count());

        try {
            SupplierSettlementInvoice::query()->findOrFail($returnedId);
            $this->fail('Returned reference ids must not resolve as settlement invoices.');
        } catch (ModelNotFoundException) {
            // Expected: returned references are display-only and are not payable invoices.
        }

        $this->assertSame(0, DB::table('supplier_payments')->count());
    }

    public function test_no_partial_negative_or_zero_payments_and_no_currency_mixing(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $service = $this->paymentService();
        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $actor = User::query()->findOrFail($fixture['adminId']);

        foreach (['0.00', '-10.00', '50.00'] as $index => $badAmount) {
            try {
                $service->record(
                    $invoice,
                    $actor,
                    $badAmount,
                    (int) $fixture['currencyId'],
                    'pay-bad-'.$index,
                    true,
                );
                $this->fail("Expected amount {$badAmount} to be rejected.");
            } catch (SupplierSettlementException $exception) {
                $this->assertContains(
                    $exception->errorCode,
                    [
                        'SETTLEMENT_PAYMENT_AMOUNT_NOT_POSITIVE',
                        'SETTLEMENT_PAYMENT_AMOUNT_INVALID',
                        'SETTLEMENT_PAYMENT_AMOUNT_MISMATCH',
                    ],
                );
            }
        }

        $this->assertSame(0, DB::table('supplier_payments')->count());
    }

    public function test_historical_ledger_rows_remain_unchanged_after_reversal(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $payment = $this->recordPayment($fixture, bankReference: 'BANK-REF-1', idempotencyKey: 'pay-hist');
        $before = (array) DB::table('supplier_payments')->where('id', $payment->id)->first();

        $this->reversePayment($payment, $fixture, idempotencyKey: 'rev-hist');

        $after = (array) DB::table('supplier_payments')->where('id', $payment->id)->first();
        foreach (['id', 'uuid', 'payment_number', 'entry_type', 'invoice_id', 'amount', 'currency_id',
            'currency_code_snapshot', 'bank_reference', 'confirmed_by', 'idempotency_key',
            'reverses_payment_id', 'reversal_reason', 'bank_export_line_id'] as $column) {
            $this->assertSame($before[$column], $after[$column], "PAYMENT column {$column} must remain immutable");
        }
    }

    public function test_payment_may_reference_export_line_without_changing_export_status(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $export = $this->prepareExport($fixture);
        $lineId = (int) $export->exportLines->first()->id;

        $payment = $this->recordPayment(
            $fixture,
            bankExportLineId: $lineId,
            idempotencyKey: 'pay-with-line',
        );

        $this->assertSame($lineId, (int) $payment->bank_export_line_id);
        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->fresh()->status);
        $this->assertNull($export->fresh()->superseded_at);
    }

    public function test_confirmed_payment_without_export_is_allowed_for_locked_invoice(): void
    {
        $fixture = $this->seedLockedPayableFixture();
        $payment = $this->recordPayment($fixture, idempotencyKey: 'pay-no-export');

        $this->assertNull($payment->bank_export_line_id);
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_PAID, $payment->invoice->payment_status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, int|string>
     */
    private function seedLockedPayableFixture(array $overrides = []): array
    {
        $currencyId = DB::table('currencies')->insertGetId(['code' => 'LKR']);
        $marketId = DB::table('markets')->insertGetId([
            'code' => 'lk',
            'currency_id' => $currencyId,
            'market_number' => 1,
        ]);
        $companyId = DB::table('companies')->insertGetId(['name' => 'Supplier Co']);
        $platformCompanyId = DB::table('companies')->insertGetId(['name' => 'Platform Co']);
        $userId = DB::table('users')->insertGetId(['company_id' => $companyId]);
        $adminId = DB::table('users')->insertGetId(['company_id' => $platformCompanyId]);

        $originAccountId = DB::table('company_bank_accounts')->insertGetId([
            'company_id' => $platformCompanyId,
            'uuid' => (string) Str::uuid(),
            'account_name' => 'Platform Origin',
            'bank_name' => 'Commercial Bank',
            'branch_name' => 'Head Office',
            'bank_code' => '7056',
            'branch_code' => '001',
            'account_number' => '000123456789012',
        ]);
        $beneficiaryAccountId = DB::table('company_bank_accounts')->insertGetId([
            'company_id' => $companyId,
            'uuid' => (string) Str::uuid(),
            'account_name' => 'Supplier Beneficiary',
            'bank_name' => 'Commercial Bank',
            'branch_name' => 'Colombo',
            'bank_code' => '7010',
            'branch_code' => '001',
            'account_number' => '000987654321098',
        ]);

        DB::table('supplier_settlement_account_designations')->insert([
            [
                'uuid' => 'DES'.Str::upper(Str::random(7)),
                'role' => SupplierSettlementAccountDesignation::ROLE_PLATFORM_ORIGIN,
                'company_id' => $platformCompanyId,
                'currency_id' => $currencyId,
                'company_bank_account_id' => $originAccountId,
                'designated_by' => $adminId,
                'designated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => 'DES'.Str::upper(Str::random(7)),
                'role' => SupplierSettlementAccountDesignation::ROLE_SUPPLIER_BENEFICIARY,
                'company_id' => $companyId,
                'currency_id' => $currencyId,
                'company_bank_account_id' => $beneficiaryAccountId,
                'designated_by' => $adminId,
                'designated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $batchNumber = $overrides['batch_number'] ?? 'SSB-LK-20261012-DAILY-1';
        $suffix = $overrides['invoice_suffix'] ?? '1';
        $invoiceNumber = 'SSI-'.$batchNumber.'-'.$companyId.'-LKR-'.$suffix;
        $payableTotal = $overrides['payable_total'] ?? '100.00';

        $batchId = DB::table('supplier_settlement_batches')->insertGetId([
            'uuid' => 'BAT'.Str::upper(Str::random(7)),
            'batch_number' => $batchNumber,
            'market_id' => $marketId,
            'market_code_snapshot' => 'LK',
            'timezone_snapshot' => 'Asia/Colombo',
            'settlement_date' => '2026-10-12',
            'kind' => SupplierSettlementBatch::KIND_DAILY,
            'sequence' => (int) ($overrides['sequence'] ?? 1),
            'status' => SupplierSettlementBatch::STATUS_LOCKED,
            'generation_source' => SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
            'generated_by' => $adminId,
            'generated_at' => now(),
            'locked_by' => $adminId,
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('supplier_settlement_invoices')->insertGetId([
            'uuid' => 'INV'.Str::upper(Str::random(7)),
            'invoice_number' => Str::limit($invoiceNumber, 40, ''),
            'batch_id' => $batchId,
            'supplier_company_id' => $companyId,
            'supplier_company_name_snapshot' => 'Supplier Co',
            'currency_id' => $currencyId,
            'currency_code_snapshot' => 'LKR',
            'payable_total' => $payableTotal,
            'transfer_fee_amount' => 0,
            'transfer_fee_currency_code_snapshot' => null,
            'transfer_fee_status' => 'transfer_fee_not_configured',
            'net_payable' => $payableTotal,
            'payment_status' => SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID,
            'instruction_status' => SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            'effective_payment_id' => null,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'currencyId' => $currencyId,
            'marketId' => $marketId,
            'companyId' => $companyId,
            'platformCompanyId' => $platformCompanyId,
            'userId' => $userId,
            'adminId' => $adminId,
            'originAccountId' => $originAccountId,
            'beneficiaryAccountId' => $beneficiaryAccountId,
            'batchId' => $batchId,
            'invoiceId' => $invoiceId,
            'batchNumber' => $batchNumber,
            'invoiceNumber' => $invoiceNumber,
            'payableTotal' => $payableTotal,
        ];
    }

    /**
     * @param  array<string, int|string>  $fixture
     */
    private function recordPayment(
        array $fixture,
        string $amount = '100.00',
        string $idempotencyKey = 'pay-default',
        bool $bankTransferConfirmed = true,
        ?string $bankReference = null,
        ?int $bankExportLineId = null,
    ): SupplierPayment {
        return $this->paymentService()->record(
            SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']),
            User::query()->findOrFail($fixture['adminId']),
            $amount,
            (int) $fixture['currencyId'],
            $idempotencyKey,
            $bankTransferConfirmed,
            $bankReference,
            $bankExportLineId,
        );
    }

    /**
     * @param  array<string, int|string>  $fixture
     */
    private function reversePayment(
        SupplierPayment $payment,
        array $fixture,
        string $reason = 'Bank rejected transfer',
        string $idempotencyKey = 'rev-default',
    ): SupplierPayment {
        return $this->paymentService()->reverse(
            $payment,
            User::query()->findOrFail($fixture['adminId']),
            $reason,
            $idempotencyKey,
        );
    }

    /**
     * @param  array<string, int|string>  $fixture
     */
    private function prepareExport(array $fixture): SupplierSettlementBankExport
    {
        return $this->app->make(SupplierSettlementBankExportService::class)->prepare(
            SupplierSettlementBatch::query()->findOrFail($fixture['batchId']),
            (int) $fixture['currencyId'],
            User::query()->findOrFail($fixture['adminId']),
        );
    }

    private function paymentService(): SupplierSettlementPaymentService
    {
        return new SupplierSettlementPaymentService(
            $this->app->make(MoneyNormalizer::class),
            $this->app->make(SupplierSettlementNumberGenerator::class),
            $this->app->make(FinancialDailySummaryUpdater::class),
        );
    }
}
