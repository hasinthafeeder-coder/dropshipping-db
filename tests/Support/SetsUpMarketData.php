<?php

namespace Tests\Support;

use Feeder\Core\Models\Company;
use Feeder\Core\Models\Country;
use Feeder\Core\Models\Currency;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerMarketAccess;
use Feeder\Core\Services\MarketDefaultCompanyCommissionService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Str;

trait SetsUpMarketData
{
    protected function seedMarketLookups(): void
    {
        $currencies = [
            ['iso_code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs'],
            ['iso_code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM'],
            ['iso_code' => 'THB', 'name' => 'Thai Baht', 'symbol' => '฿'],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->firstOrCreate(
                ['iso_code' => $currency['iso_code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $currency['name'],
                    'symbol' => $currency['symbol'],
                    'decimal_places' => 2,
                    'is_active' => true,
                ]
            );
        }

        $countries = [
            ['iso_code' => 'LK', 'name' => 'Sri Lanka', 'phone_country_code' => '+94'],
            ['iso_code' => 'MY', 'name' => 'Malaysia', 'phone_country_code' => '+60'],
            ['iso_code' => 'TH', 'name' => 'Thailand', 'phone_country_code' => '+66'],
        ];

        foreach ($countries as $country) {
            Country::query()->firstOrCreate(
                ['iso_code' => $country['iso_code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $country['name'],
                    'phone_country_code' => $country['phone_country_code'],
                    'is_active' => true,
                ]
            );
        }

        $markets = [
            ['code' => 'lk', 'name' => 'Sri Lanka', 'country_iso_code' => 'LK', 'currency_iso_code' => 'LKR', 'is_active' => true],
            ['code' => 'my', 'name' => 'Malaysia', 'country_iso_code' => 'MY', 'currency_iso_code' => 'MYR', 'is_active' => true],
            ['code' => 'th', 'name' => 'Thailand', 'country_iso_code' => 'TH', 'currency_iso_code' => 'THB', 'is_active' => false],
        ];

        foreach ($markets as $market) {
            $countryId = Country::query()->where('iso_code', $market['country_iso_code'])->value('id');
            $currencyId = Currency::query()->where('iso_code', $market['currency_iso_code'])->value('id');

            if ($countryId === null || $currencyId === null) {
                continue;
            }

            Market::query()->firstOrCreate(
                ['code' => $market['code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $market['name'],
                    'country_id' => $countryId,
                    'currency_id' => $currencyId,
                    'is_active' => $market['is_active'],
                ]
            );
        }

        $this->seedMarketDefaultCommissions();
        $this->seedMarketFinancialDefaults();
    }

    protected function seedMarketFinancialDefaults(): void
    {
        if (! class_exists(\Feeder\Core\Services\ResellerServiceChargeService::class)) {
            return;
        }

        $serviceChargeService = app(\Feeder\Core\Services\ResellerServiceChargeService::class);
        $introducerBonusService = app(\Feeder\Core\Services\IntroducerBonusService::class);

        foreach (\Feeder\Core\Services\ResellerServiceChargeService::MARKET_DEFAULTS as $marketCode => $amount) {
            $market = Market::query()->where('code', $marketCode)->first();

            if ($market === null || $serviceChargeService->hasDefaultCharge($market)) {
                continue;
            }

            $serviceChargeService->setDefaultCharge($market, $amount);
        }

        foreach (\Feeder\Core\Services\IntroducerBonusService::MARKET_DEFAULTS as $marketCode => $amount) {
            $market = Market::query()->where('code', $marketCode)->first();

            if ($market === null || $introducerBonusService->hasIntroducerBonus($market)) {
                continue;
            }

            $introducerBonusService->setIntroducerBonus($market, $amount);
        }
    }

    protected function seedMarketDefaultCommissions(): void
    {
        if (! class_exists(\Feeder\Core\Services\MarketDefaultCompanyCommissionService::class)) {
            return;
        }

        $service = app(\Feeder\Core\Services\MarketDefaultCompanyCommissionService::class);

        foreach (\Feeder\Core\Services\MarketDefaultCompanyCommissionService::MARKET_DEFAULTS as $marketCode => $amount) {
            $market = Market::query()->where('code', $marketCode)->first();

            if ($market === null || $service->hasDefaultCompanyCommission($market)) {
                continue;
            }

            $service->setDefaultCompanyCommission($market, $amount);
        }
    }

    protected function marketByCode(string $code): Market
    {
        return Market::query()->where('code', $code)->firstOrFail();
    }

    protected function countryByIso(string $isoCode): Country
    {
        return Country::query()->where('iso_code', $isoCode)->firstOrFail();
    }

    protected function grantResellerMarketAccess(Company $resellerCompany, Market $market): void
    {
        ResellerMarketAccess::query()->firstOrCreate([
            'company_id' => $resellerCompany->id,
            'market_id' => $market->id,
        ], [
            'uuid' => (string) Str::uuid(),
        ]);
    }

    protected function configureSupplierCompany(Company $company, string $marketCode = 'lk'): void
    {
        $company->forceFill([
            'operation_market_id' => $this->marketByCode($marketCode)->id,
        ])->save();
    }

    protected function configureResellerCompany(Company $company, array $marketCodes = ['lk'], ?string $homeCountryIso = 'LK'): void
    {
        if ($homeCountryIso !== null) {
            $company->forceFill([
                'home_country_id' => $this->countryByIso($homeCountryIso)->id,
            ])->save();
        }

        foreach ($marketCodes as $marketCode) {
            $this->grantResellerMarketAccess($company, $this->marketByCode($marketCode));
        }
    }
}
