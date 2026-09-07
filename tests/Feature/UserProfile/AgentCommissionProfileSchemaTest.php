<?php

namespace Tests\Feature\UserProfile;

use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\User;
use Feeder\Core\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentCommissionProfileSchemaTest extends TestCase
{
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
        DB::purge('mysql');
        DB::reconnect('mysql');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_agent_commission_per_order_column_exists_as_nullable_decimal(): void
    {
        $this->assertTrue(
            Schema::hasColumn('user_profiles', 'agent_commission_per_order'),
            'user_profiles.agent_commission_per_order must exist.'
        );

        $column = $this->describeUserProfileColumn('agent_commission_per_order');

        $this->assertSame('YES', $column->Null, 'agent_commission_per_order must be nullable.');
        $this->assertSame(
            'decimal(15,2)',
            strtolower((string) $column->Type),
            'agent_commission_per_order must be decimal(15,2).'
        );
        $this->assertTrue(
            $column->Default === null || $column->Default === '',
            'agent_commission_per_order must not have a database default.'
        );
    }

    public function test_nic_column_is_nullable_and_unique_constraint_remains(): void
    {
        $column = $this->describeUserProfileColumn('nic');

        $this->assertSame('YES', $column->Null, 'user_profiles.nic must be nullable.');

        $indexes = DB::select(
            'SELECT INDEX_NAME, NON_UNIQUE AS Non_unique, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['user_profiles', 'nic']
        );
        $uniqueIndexes = array_filter(
            $indexes,
            static fn (object $index): bool => (int) $index->Non_unique === 0
        );

        $this->assertNotEmpty(
            $uniqueIndexes,
            'user_profiles.nic must retain a unique index for non-null values.'
        );
    }

    public function test_call_center_agents_table_was_not_created(): void
    {
        $this->assertFalse(
            Schema::hasTable('call_center_agents'),
            'call_center_agents table must not exist.'
        );
    }

    public function test_users_username_column_was_not_added(): void
    {
        $this->assertFalse(
            Schema::hasColumn('users', 'username'),
            'users.username must not be added in O1.4-B.'
        );
    }

    public function test_profile_accepts_null_nic_and_null_commission(): void
    {
        $user = $this->createUser('owner-null-nic@schema.test');

        $profile = UserProfile::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'first_name' => 'Owner',
            'last_name' => 'WithoutNic',
            'nic' => null,
            'agent_commission_per_order' => null,
        ]);

        $fresh = $profile->fresh();

        $this->assertNull($fresh->nic);
        $this->assertNull($fresh->agent_commission_per_order);
    }

    public function test_existing_non_null_nic_values_remain_valid(): void
    {
        $user = $this->createUser('owner-with-nic@schema.test');
        $nic = '199012345678';

        $profile = UserProfile::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'first_name' => 'Owner',
            'last_name' => 'WithNic',
            'nic' => $nic,
            'agent_commission_per_order' => null,
        ]);

        $this->assertSame($nic, $profile->fresh()->nic);
        $this->assertDatabaseHas('user_profiles', [
            'id' => $profile->id,
            'nic' => $nic,
        ]);
    }

    public function test_agent_commission_values_are_cast_as_decimal_strings(): void
    {
        foreach (['75.00', '100.00', '150.50'] as $amount) {
            $user = $this->createUser('agent-commission-'.Str::uuid().'@schema.test');

            $profile = UserProfile::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'first_name' => 'Agent',
                'last_name' => 'Commission',
                'nic' => null,
                'agent_commission_per_order' => $amount,
            ]);

            $this->assertSame($amount, $profile->fresh()->agent_commission_per_order);
        }
    }

    public function test_non_agent_profile_can_keep_null_commission(): void
    {
        $user = $this->createUser('supplier-or-owner@schema.test', UserType::OWNER);

        $profile = UserProfile::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'first_name' => 'Normal',
            'last_name' => 'Owner',
            'nic' => '198812345V',
            'agent_commission_per_order' => null,
        ]);

        $this->assertNull($profile->fresh()->agent_commission_per_order);
        $this->assertSame('198812345V', $profile->fresh()->nic);
    }

    /**
     * @return object{Field: string, Type: string, Null: string, Key: string, Default: mixed, Extra: string}
     */
    private function describeUserProfileColumn(string $column): object
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS Field, COLUMN_TYPE AS Type, IS_NULLABLE AS `Null`, COLUMN_DEFAULT AS `Default`
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['user_profiles', $column]
        );

        $this->assertNotEmpty($rows, "Missing user_profiles.{$column} column.");

        return $rows[0];
    }

    private function createUser(string $email, UserType $userType = UserType::EMPLOYEE): User
    {
        return User::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => null,
            'role_id' => null,
            'email' => $email,
            'phone' => null,
            'password' => Hash::make('password'),
            'user_type' => $userType->value,
            'status' => UserStatus::ACTIVE->value,
            'is_master_reseller' => false,
        ]);
    }
}
