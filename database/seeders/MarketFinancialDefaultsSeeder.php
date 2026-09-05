<?php

namespace Database\Seeders;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\Setting;
use Feeder\Core\Services\IntroducerBonusService;
use Feeder\Core\Services\ResellerServiceChargeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MarketFinancialDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $legacyServiceCharge = Setting::query()
            ->where('key', 'reseller_service_charge')
            ->whereNull('market_id')
            ->value('value');

        $legacyIntroducerBonus = Setting::query()
            ->where('key', 'introducer_bonus')
            ->whereNull('market_id')
            ->value('value');

        $serviceChargeDefaults = ResellerServiceChargeService::MARKET_DEFAULTS;
        $introducerBonusDefaults = IntroducerBonusService::MARKET_DEFAULTS;

        if (is_string($legacyServiceCharge) && $legacyServiceCharge !== '') {
            $serviceChargeDefaults['lk'] = number_format((float) $legacyServiceCharge, 2, '.', '');
        }

        if (is_string($legacyIntroducerBonus) && $legacyIntroducerBonus !== '') {
            $introducerBonusDefaults['lk'] = number_format((float) $legacyIntroducerBonus, 2, '.', '');
        }

        $serviceChargeService = app(ResellerServiceChargeService::class);
        $introducerBonusService = app(IntroducerBonusService::class);

        foreach ($serviceChargeDefaults as $marketCode => $amount) {
            $market = Market::query()->where('code', $marketCode)->first();

            if ($market === null || $serviceChargeService->hasDefaultCharge($market)) {
                continue;
            }

            $serviceChargeService->setDefaultCharge($market, $amount);
        }

        foreach ($introducerBonusDefaults as $marketCode => $amount) {
            $market = Market::query()->where('code', $marketCode)->first();

            if ($market === null || $introducerBonusService->hasIntroducerBonus($market)) {
                continue;
            }

            $introducerBonusService->setIntroducerBonus($market, $amount);
        }
    }
}
