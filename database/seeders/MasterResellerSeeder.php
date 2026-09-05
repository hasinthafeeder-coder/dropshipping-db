<?php

namespace Database\Seeders;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\User;
use Feeder\Core\Services\MasterResellerService;
use Feeder\Core\Services\Referral\ReferralService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MasterResellerSeeder extends Seeder
{
    public function run(): void
    {
        $portal = Portal::query()->firstOrCreate(
            ['code' => PortalCode::RESELLER->value],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Reseller Portal',
                'subdomain' => 'reseller',
                'description' => 'Reseller Portal',
                'is_active' => true,
            ]
        );

        $company = Company::query()->firstOrCreate(
            ['phone' => '0700000001'],
            [
                'uuid' => (string) Str::uuid(),
                'portal_id' => $portal->id,
                'name' => 'Master Reseller Company',
                'email' => 'master.reseller@feeder.local',
                'phone' => '0700000001',
                'registration_number' => 'MR-001',
                'status' => CompanyStatus::ACTIVE->value,
            ]
        );

        $user = User::query()->firstOrCreate(
            ['email' => 'master.reseller@feeder.local'],
            [
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'phone' => '0700000001',
                'password' => Hash::make('password'),
                'user_type' => UserType::OWNER->value,
                'status' => UserStatus::ACTIVE->value,
                'phone_verified_at' => now(),
            ]
        );

        $company->forceFill(['owner_user_id' => $user->id])->save();
        $user->forceFill(['company_id' => $company->id])->save();

        app(MasterResellerService::class)->setMaster($user);
        app(ReferralService::class)->ensureUserHasReferralCode($user);
    }
}
