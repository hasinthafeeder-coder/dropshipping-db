<?php

namespace Tests\Feature\Courier;

use Feeder\Core\Contracts\Courier\CourierConnectionTester;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Services\Courier\CourierConnectionResult;
use Feeder\Core\Services\Courier\CourierConnectionService;
use Feeder\Core\Services\Courier\CourierConnectionTesterResolver;
use Feeder\Core\Services\Courier\SupplierCourierAccountService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class SupplierCourierAccountFoundationTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private SupplierCourierAccountService $accounts;

    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'dropshipping',
            'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => 'admin',
        ]);
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::reconnect('mysql');
        \Illuminate\Support\Facades\DB::beginTransaction();

        $this->seedMarketLookups();
        $this->accounts = app(SupplierCourierAccountService::class);
        $this->actorId = $this->makeAdminActor()->id;
    }

    protected function tearDown(): void
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_create_encrypts_credentials_and_hides_them_from_presentation(): void
    {
        $supplier = $this->makeSupplierUser();
        $courier = $this->makeCourier('FOUND_A');

        $account = $this->accounts->create($supplier, [
            'courier_id' => $courier->id,
            'account_label' => 'Primary A',
            'credentials' => [
                'account_reference' => 'BUSINESS-1234',
                'api_key' => 'super-secret-key',
            ],
        ], $this->actorId);

        $this->assertNotSame('super-secret-key', $account->credentials_encrypted);
        $this->assertSame('super-secret-key', $account->getCredentials()['api_key']);
        $this->assertTrue($account->relationLoaded('courier') || $account->courier !== null);

        $hidden = $account->toArray();
        $this->assertArrayNotHasKey('credentials_encrypted', $hidden);

        $presented = $this->accounts->presentAccount($account);
        $this->assertStringContainsString('1234', (string) $presented['mask_summary']);
        $this->assertStringNotContainsString('super-secret-key', json_encode($presented));
        $this->assertSame($this->actorId, $account->created_by);
        $this->assertSame($this->actorId, $account->updated_by);
    }

    public function test_duplicate_supplier_courier_is_rejected(): void
    {
        $supplier = $this->makeSupplierUser();
        $courier = $this->makeCourier('FOUND_B');

        $this->accounts->create($supplier, [
            'courier_id' => $courier->id,
            'account_label' => 'First',
            'credentials' => ['api_key' => 'key-one'],
        ], $this->actorId);

        $this->expectException(ValidationException::class);

        $this->accounts->create($supplier, [
            'courier_id' => $courier->id,
            'account_label' => 'Second',
            'credentials' => ['api_key' => 'key-two'],
        ], $this->actorId);
    }

    public function test_supplier_isolation_on_require(): void
    {
        $supplierA = $this->makeSupplierUser();
        $supplierB = $this->makeSupplierUser();
        $courier = $this->makeCourier('FOUND_C');

        $account = $this->accounts->create($supplierA, [
            'courier_id' => $courier->id,
            'account_label' => 'A Account',
            'credentials' => ['api_key' => 'key-a'],
        ], $this->actorId);

        $this->expectException(ValidationException::class);
        $this->accounts->requireForSupplier($supplierB, $account->uuid);
    }

    public function test_empty_secret_fields_preserve_existing_credentials_on_update(): void
    {
        $supplier = $this->makeSupplierUser();
        $courier = $this->makeCourier('FOUND_D');

        $account = $this->accounts->create($supplier, [
            'courier_id' => $courier->id,
            'account_label' => 'Original',
            'credentials' => [
                'account_reference' => 'REF-9999',
                'api_key' => 'keep-me',
            ],
        ], $this->actorId);

        $updated = $this->accounts->update($account, [
            'account_label' => 'Renamed',
            'credentials' => [
                'account_reference' => 'REF-8888',
                'api_key' => '',
            ],
        ], $this->actorId);

        $this->assertSame('Renamed', $updated->account_label);
        $this->assertSame('keep-me', $updated->getCredentials()['api_key']);
        $this->assertSame('REF-8888', $updated->getCredentials()['account_reference']);
    }

    public function test_default_rules_zero_one_and_replace(): void
    {
        $supplier = $this->makeSupplierUser();
        $courierA = $this->makeCourier('DEF_A');
        $courierB = $this->makeCourier('DEF_B');

        $accountA = $this->accounts->create($supplier, [
            'courier_id' => $courierA->id,
            'account_label' => 'A',
            'credentials' => ['api_key' => 'a'],
            'is_default' => false,
        ], $this->actorId);

        $this->assertFalse($accountA->is_default);

        $accountA = $this->accounts->setDefault($accountA, $this->actorId);
        $this->assertTrue($accountA->fresh()->is_default);

        $accountB = $this->accounts->create($supplier, [
            'courier_id' => $courierB->id,
            'account_label' => 'B',
            'credentials' => ['api_key' => 'b'],
            'is_default' => true,
        ], $this->actorId);

        $this->assertTrue($accountB->fresh()->is_default);
        $this->assertFalse($accountA->fresh()->is_default);

        $this->accounts->clearDefault($accountB, $this->actorId);
        $this->assertFalse($accountB->fresh()->is_default);
        $this->assertSame(0, SupplierCourierAccount::query()
            ->where('supplier_id', $supplier->id)
            ->where('is_default', true)
            ->count());
    }

    public function test_deactivate_clears_default(): void
    {
        $supplier = $this->makeSupplierUser();
        $courier = $this->makeCourier('DEF_C');

        $account = $this->accounts->create($supplier, [
            'courier_id' => $courier->id,
            'account_label' => 'Default',
            'credentials' => ['api_key' => 'x'],
            'is_default' => true,
        ], $this->actorId);

        $deactivated = $this->accounts->deactivate($account, $this->actorId);

        $this->assertFalse($deactivated->is_active);
        $this->assertFalse($deactivated->is_default);
    }

    public function test_inactive_account_cannot_be_set_as_default(): void
    {
        $supplier = $this->makeSupplierUser();
        $courier = $this->makeCourier('DEF_D');

        $account = $this->accounts->create($supplier, [
            'courier_id' => $courier->id,
            'account_label' => 'Inactive',
            'credentials' => ['api_key' => 'x'],
            'is_active' => false,
        ], $this->actorId);

        $this->expectException(ValidationException::class);
        $this->accounts->setDefault($account, $this->actorId);
    }

    public function test_connection_test_unavailable_without_tester(): void
    {
        $supplier = $this->makeSupplierUser();
        $courier = $this->makeCourier('NO_TESTER');

        $account = $this->accounts->create($supplier, [
            'courier_id' => $courier->id,
            'account_label' => 'No Tester',
            'credentials' => ['api_key' => 'x'],
        ], $this->actorId);

        $result = app(CourierConnectionService::class)->testAccount($account);

        $this->assertFalse($result->available);
        $this->assertFalse($result->successful);
        $this->assertStringContainsString('not available', $result->message);
        $this->assertStringNotContainsString('x', $result->message);
    }

    public function test_connection_test_success_and_failure_without_leaking_secrets(): void
    {
        $supplier = $this->makeSupplierUser();
        $courier = $this->makeCourier('WITH_TESTER');

        $account = $this->accounts->create($supplier, [
            'courier_id' => $courier->id,
            'account_label' => 'Tester',
            'credentials' => ['api_key' => 'secret-value-xyz'],
        ], $this->actorId);

        $resolver = app(CourierConnectionTesterResolver::class);

        $resolver->register($courier->code, new class implements CourierConnectionTester
        {
            public function test(array $credentials): CourierConnectionResult
            {
                if (($credentials['api_key'] ?? null) === 'secret-value-xyz') {
                    return CourierConnectionResult::success('Connection successful.');
                }

                return CourierConnectionResult::failure('Connection failed: Invalid credentials');
            }
        });

        $success = app(CourierConnectionService::class)->testAccount($account);
        $this->assertTrue($success->successful);
        $this->assertStringNotContainsString('secret-value-xyz', $success->message);

        $account->setCredentials(['api_key' => 'wrong']);
        $account->save();

        $failure = app(CourierConnectionService::class)->testAccount($account->fresh());
        $this->assertTrue($failure->available);
        $this->assertFalse($failure->successful);
        $this->assertStringNotContainsString('wrong', $failure->message);
        $this->assertStringNotContainsString('secret-value-xyz', $failure->message);
    }

    public function test_database_enforces_single_active_default(): void
    {
        $supplier = $this->makeSupplierUser();
        $courierA = $this->makeCourier('DB_DEF_A');
        $courierB = $this->makeCourier('DB_DEF_B');

        SupplierCourierAccount::query()->create([
            'supplier_id' => $supplier->id,
            'courier_id' => $courierA->id,
            'account_label' => 'A',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'a'], JSON_THROW_ON_ERROR)),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        SupplierCourierAccount::query()->create([
            'supplier_id' => $supplier->id,
            'courier_id' => $courierB->id,
            'account_label' => 'B',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'b'], JSON_THROW_ON_ERROR)),
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function makeCourier(string $code): Courier
    {
        return Courier::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'name' => 'Courier '.$code,
            'is_active' => true,
        ]);
    }

    private function makeAdminActor(): \Feeder\Core\Models\User
    {
        return \Feeder\Core\Models\User::query()->create([
            'uuid' => (string) Str::uuid(),
            'email' => 'admin-courier-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '070'.random_int(1000000, 9999999),
            'password' => bcrypt('password'),
            'user_type' => \Feeder\Core\Enums\UserType::SUPER_ADMIN->value,
            'status' => \Feeder\Core\Enums\UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);
    }
}
