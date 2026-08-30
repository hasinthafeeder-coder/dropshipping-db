<?php

use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerMarketServiceChargeOverride;
use Feeder\Core\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $lkMarket = Market::query()->where('code', 'lk')->first();

        if ($lkMarket === null) {
            return;
        }

        User::query()
            ->whereNotNull('reseller_service_charge_override')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($lkMarket): void {
                foreach ($users as $user) {
                    $amount = $user->reseller_service_charge_override;

                    if ($amount === null || $amount === '') {
                        continue;
                    }

                    ResellerMarketServiceChargeOverride::query()->firstOrCreate(
                        [
                            'user_id' => $user->id,
                            'market_id' => $lkMarket->id,
                        ],
                        [
                            'uuid' => (string) Str::uuid(),
                            'amount' => number_format((float) $amount, 2, '.', ''),
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        $lkMarket = Market::query()->where('code', 'lk')->first();

        if ($lkMarket === null) {
            return;
        }

        ResellerMarketServiceChargeOverride::query()
            ->where('market_id', $lkMarket->id)
            ->delete();
    }
};
