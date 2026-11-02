<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issue a referral registration code to each active reseller owner who does not
 * already have one. Does not create, update, or delete referral relationships.
 * Does not reactivate an inactive or soft-deleted code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'RESELLER')->value('id');

        if (! $portalId) {
            return;
        }

        $userIds = DB::table('users')
            ->join('companies', 'companies.id', '=', 'users.company_id')
            ->where('users.user_type', 'OWNER')
            ->where('users.status', 'ACTIVE')
            ->whereNull('users.deleted_at')
            ->whereNull('companies.deleted_at')
            ->where('companies.portal_id', $portalId)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('referral_codes')
                    ->whereColumn('referral_codes.user_id', 'users.id');
            })
            ->orderBy('users.id')
            ->pluck('users.id');

        $now = now();

        foreach ($userIds as $userId) {
            DB::table('referral_codes')->insert([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'code' => $this->uniqueCode(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Issued codes are retained. Removing them cannot be distinguished from
        // codes created later, and this migration never stored referral relationships.
    }

    private function uniqueCode(): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $code = strtoupper(Str::random(8));

            $exists = DB::table('referral_codes')->where('code', $code)->exists();

            if (! $exists) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique referral code.');
    }
};
