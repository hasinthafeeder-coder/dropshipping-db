<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Feeder\Core\Models\User;
use Feeder\Core\Models\UserProfile;
use Feeder\Core\Enums\ApplicationType;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $portal = Portal::firstOrCreate([
            'code' => ApplicationType::ADMIN->value,
        ], [
            'uuid' => Str::uuid(),
            'name' => 'Admin Portal',
            'subdomain' => 'admin',
            'description' => 'Feeder Admin Portal',
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate([
            'portal_id' => $portal->id,
            'slug' => 'super-admin',
        ], [
            'uuid' => Str::uuid(),
            'company_id' => null,
            'name' => 'Super Admin',
            'description' => 'Super Admin Role',
            'is_system' => true,
        ]);

        $user = User::firstOrCreate([
            'email' => 'admin@feeder.local',
        ], [
            'uuid' => Str::uuid(),
            'company_id' => null,
            'role_id' => $role->id,
            'phone' => '0700000000',
            'password' => Hash::make('password'),
            'user_type' => UserType::SUPER_ADMIN->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);

        UserProfile::firstOrCreate([
            'user_id' => $user->id,
        ], [
            'uuid' => Str::uuid(),
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'nic' => '000000000V',
        ]);
    }
}
