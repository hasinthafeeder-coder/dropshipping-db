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
use Feeder\Core\Services\MasterResellerService;
use Feeder\Core\Services\Referral\ReferralService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamTreeDummySeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MasterResellerSeeder::class);

        $referralService = app(ReferralService::class);
        $masterResellerService = app(MasterResellerService::class);

        $masterUser = $masterResellerService->getMaster();
        if ($masterUser === null) {
            return;
        }

        $resellerPortal = Portal::query()->where('code', PortalCode::RESELLER->value)->firstOrFail();
        $ownerRoleId = Role::query()
            ->where('slug', 'owner')
            ->whereHas('portal', fn($query) => $query->where('code', PortalCode::RESELLER->value))
            ->value('id');

        $nodes = [
            ['key' => 'A', 'company' => 'Alpha Traders', 'parent' => 'master'],
            ['key' => 'B', 'company' => 'Bravo Commerce', 'parent' => 'master'],
            ['key' => 'C', 'company' => 'Cosmo Ventures', 'parent' => 'master'],
            ['key' => 'A1', 'company' => 'Alpha North', 'parent' => 'A'],
            ['key' => 'A2', 'company' => 'Alpha South', 'parent' => 'A'],
            ['key' => 'A3', 'company' => 'Alpha East', 'parent' => 'A'],
            ['key' => 'B1', 'company' => 'Bravo One', 'parent' => 'B'],
            ['key' => 'B2', 'company' => 'Bravo Two', 'parent' => 'B'],
            ['key' => 'C1', 'company' => 'Cosmo One', 'parent' => 'C'],
            ['key' => 'C2', 'company' => 'Cosmo Two', 'parent' => 'C'],
            ['key' => 'A1A', 'company' => 'Alpha North Prime', 'parent' => 'A1'],
            ['key' => 'A1B', 'company' => 'Alpha North Next', 'parent' => 'A1'],
            ['key' => 'A2A', 'company' => 'Alpha South Prime', 'parent' => 'A2'],
            ['key' => 'A3A', 'company' => 'Alpha East Prime', 'parent' => 'A3'],
            ['key' => 'B1A', 'company' => 'Bravo One Prime', 'parent' => 'B1'],
            ['key' => 'B1B', 'company' => 'Bravo One Next', 'parent' => 'B1'],
            ['key' => 'B2A', 'company' => 'Bravo Two Prime', 'parent' => 'B2'],
            ['key' => 'C1A', 'company' => 'Cosmo One Prime', 'parent' => 'C1'],
            ['key' => 'C1B', 'company' => 'Cosmo One Next', 'parent' => 'C1'],
            ['key' => 'C2A', 'company' => 'Cosmo Two Prime', 'parent' => 'C2'],
            ['key' => 'C2B', 'company' => 'Cosmo Two Next', 'parent' => 'C2'],
        ];

        $usersByKey = ['master' => $masterUser];

        foreach ($nodes as $index => $node) {
            $nodeNumber = $index + 1;
            $phone = sprintf('0799%06d', $nodeNumber);
            $email = sprintf('team.node%02d@feeder.local', $nodeNumber);
            $nic = sprintf('TT%09dV', $nodeNumber);

            $company = Company::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'uuid' => (string) Str::uuid(),
                    'portal_id' => $resellerPortal->id,
                    'name' => $node['company'],
                    'email' => $email,
                    'phone' => $phone,
                    'registration_number' => 'TT-' . str_pad((string) $nodeNumber, 3, '0', STR_PAD_LEFT),
                    'status' => CompanyStatus::ACTIVE->value,
                ]
            );

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'uuid' => (string) Str::uuid(),
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
                'name' => $node['company'],
            ])->save();

            $user->forceFill([
                'company_id' => $company->id,
            ])->save();

            UserProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'first_name' => 'Team',
                    'last_name' => 'Node ' . $node['key'],
                    'nic' => $nic,
                ]
            );

            $referralService->ensureUserHasReferralCode($user);
            $usersByKey[$node['key']] = $user;
        }

        foreach ($nodes as $node) {
            $child = $usersByKey[$node['key']] ?? null;
            $parent = $usersByKey[$node['parent']] ?? null;

            if (! $child || ! $parent) {
                continue;
            }

            if ($child->parentReseller()->exists()) {
                continue;
            }

            $parentCode = $referralService->ensureUserHasReferralCode($parent);
            $referralService->createPermanentRelationship($child, $parentCode->code, $masterUser);
        }
    }
}
