<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Feeder\Core\Models\User;
use Feeder\Core\Models\Role;

class AssignUserRolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::with('portal')
            ->get()
            ->groupBy(function ($role) {
                return $role->portal->code . '.' . $role->slug;
            });


        User::query()
            ->whereNull('role_id')
            ->each(function (User $user) use ($roles) {

                // Super Admin
                if ($user->user_type === 'SUPER_ADMIN') {

                    $role = $roles['ADMIN.super-admin']->first();

                    if ($role) {
                        $user->update([
                            'role_id' => $role->id,
                        ]);
                    }

                    return;
                }


                // Owners
                if ($user->user_type === 'OWNER' && $user->company) {

                    $portalCode = $user->company
                        ->portal
                        ->code;


                    $role = $roles[$portalCode . '.owner']->first();


                    if ($role) {
                        $user->update([
                            'role_id' => $role->id,
                        ]);
                    }
                }
            });
    }
}
