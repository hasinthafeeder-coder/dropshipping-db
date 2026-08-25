<?php

namespace Database\Seeders;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Feeder\Core\Models\User;
use Feeder\Core\Models\UserProfile;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SupplierAccountSeeder extends Seeder
{
    public function run(): void
    {
        $portal = Portal::query()
            ->where('code', PortalCode::SUPPLIER->value)
            ->first();

        if (! $portal) {
            $this->call([
                PortalSeeder::class,
                RoleSeeder::class,
                PermissionSeeder::class,
                RolePermissionSeeder::class,
            ]);

            $portal = Portal::query()
                ->where('code', PortalCode::SUPPLIER->value)
                ->firstOrFail();
        }

        $ownerRoleId = Role::query()
            ->where('slug', 'owner')
            ->whereHas('portal', fn($query) => $query->where('code', PortalCode::SUPPLIER->value))
            ->value('id');

        if (! $ownerRoleId) {
            $this->call(RoleSeeder::class);

            $ownerRoleId = Role::query()
                ->where('slug', 'owner')
                ->whereHas('portal', fn($query) => $query->where('code', PortalCode::SUPPLIER->value))
                ->value('id');
        }

        foreach (range(1, 10) as $index) {
            $phone = sprintf('077%06d', $index);
            $email = sprintf('supplier%02d@feeder.local', $index);
            $companyName = sprintf('Supplier %d', $index);
            $firstName = 'Supplier';
            $lastName = 'Owner ' . $index;
            $nic = sprintf('SUP%06dV', $index);

            $company = Company::query()->firstOrCreate(
                ['email' => $email],
                [
                    'uuid' => UuidService::generate(),
                    'portal_id' => $portal->id,
                    'name' => $companyName,
                    'email' => $email,
                    'phone' => $phone,
                    'registration_number' => 'SUP-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                    'tax_number' => 'TAX-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                    'status' => CompanyStatus::ACTIVE->value,
                    'approved_at' => now(),
                ]
            );

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'uuid' => UuidService::generate(),
                    'company_id' => $company->id,
                    'role_id' => $ownerRoleId,
                    'phone' => $phone,
                    'password' => Hash::make('password'),
                    'user_type' => UserType::OWNER->value,
                    'status' => UserStatus::ACTIVE->value,
                    'phone_verified_at' => now(),
                ]
            );

            if ($user->role_id === null && $ownerRoleId !== null) {
                $user->forceFill(['role_id' => $ownerRoleId])->save();
            }

            $company->forceFill([
                'owner_user_id' => $user->id,
                'name' => $companyName,
            ])->save();

            $user->forceFill([
                'company_id' => $company->id,
            ])->save();

            UserProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'uuid' => UuidService::generate(),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'nic' => $nic,
                ]
            );
        }
    }
}
