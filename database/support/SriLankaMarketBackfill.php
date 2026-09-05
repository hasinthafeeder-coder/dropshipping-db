<?php

namespace Database\Support;

use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SriLankaMarketBackfill
{
    public static function run(): void
    {
        self::seedLookupData();

        $sriLankaMarketId = DB::table('markets')
            ->where('code', 'lk')
            ->value('id');

        $sriLankaCountryId = DB::table('countries')
            ->where('iso_code', 'LK')
            ->value('id');

        if ($sriLankaMarketId === null || $sriLankaCountryId === null) {
            return;
        }

        $supplierPortalId = DB::table('portals')
            ->where('code', PortalCode::SUPPLIER->value)
            ->value('id');

        if ($supplierPortalId !== null) {
            DB::table('companies')
                ->where('portal_id', $supplierPortalId)
                ->whereNull('operation_market_id')
                ->update([
                    'operation_market_id' => $sriLankaMarketId,
                    'updated_at' => now(),
                ]);
        }

        $resellerPortalId = DB::table('portals')
            ->where('code', PortalCode::RESELLER->value)
            ->value('id');

        if ($resellerPortalId !== null) {
            DB::table('companies')
                ->where('portal_id', $resellerPortalId)
                ->whereNull('home_country_id')
                ->update([
                    'home_country_id' => $sriLankaCountryId,
                    'updated_at' => now(),
                ]);

            $resellerCompanyIds = DB::table('companies')
                ->where('portal_id', $resellerPortalId)
                ->pluck('id');

            foreach ($resellerCompanyIds as $companyId) {
                self::grantMarketAccess((int) $companyId, (int) $sriLankaMarketId);
            }
        }

        DB::table('products')
            ->whereNull('market_id')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($sriLankaMarketId): void {
                foreach ($products as $product) {
                    $marketId = $sriLankaMarketId;

                    $supplierCompanyMarketId = DB::table('users')
                        ->join('companies', 'companies.id', '=', 'users.company_id')
                        ->where('users.id', $product->supplier_id)
                        ->value('companies.operation_market_id');

                    if ($supplierCompanyMarketId !== null) {
                        $marketId = $supplierCompanyMarketId;
                    }

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'market_id' => $marketId,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public static function seedLookupData(): void
    {
        $now = now();

        foreach (self::countries() as $country) {
            $existingId = DB::table('countries')
                ->where('iso_code', $country['iso_code'])
                ->value('id');

            if ($existingId === null) {
                DB::table('countries')->insert([
                    'uuid' => UuidService::generate(),
                    'iso_code' => $country['iso_code'],
                    'name' => $country['name'],
                    'phone_country_code' => $country['phone_country_code'],
                    'is_active' => $country['is_active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (self::currencies() as $currency) {
            $existingId = DB::table('currencies')
                ->where('iso_code', $currency['iso_code'])
                ->value('id');

            if ($existingId === null) {
                DB::table('currencies')->insert([
                    'uuid' => UuidService::generate(),
                    'iso_code' => $currency['iso_code'],
                    'name' => $currency['name'],
                    'symbol' => $currency['symbol'],
                    'decimal_places' => $currency['decimal_places'],
                    'is_active' => $currency['is_active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (self::markets() as $market) {
            $existingId = DB::table('markets')
                ->where('code', $market['code'])
                ->value('id');

            if ($existingId !== null) {
                continue;
            }

            $countryId = DB::table('countries')
                ->where('iso_code', $market['country_iso_code'])
                ->value('id');

            $currencyId = DB::table('currencies')
                ->where('iso_code', $market['currency_iso_code'])
                ->value('id');

            if ($countryId === null || $currencyId === null) {
                continue;
            }

            DB::table('markets')->insert([
                'uuid' => UuidService::generate(),
                'code' => $market['code'],
                'name' => $market['name'],
                'country_id' => $countryId,
                'currency_id' => $currencyId,
                'is_active' => $market['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private static function grantMarketAccess(int $companyId, int $marketId): void
    {
        $exists = DB::table('reseller_market_access')
            ->where('company_id', $companyId)
            ->where('market_id', $marketId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('reseller_market_access')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'market_id' => $marketId,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array{iso_code: string, name: string, phone_country_code: string, is_active: bool}>
     */
    private static function countries(): array
    {
        return [
            [
                'iso_code' => 'LK',
                'name' => 'Sri Lanka',
                'phone_country_code' => '+94',
                'is_active' => true,
            ],
            [
                'iso_code' => 'MY',
                'name' => 'Malaysia',
                'phone_country_code' => '+60',
                'is_active' => true,
            ],
            [
                'iso_code' => 'TH',
                'name' => 'Thailand',
                'phone_country_code' => '+66',
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return list<array{iso_code: string, name: string, symbol: string, decimal_places: int, is_active: bool}>
     */
    private static function currencies(): array
    {
        return [
            [
                'iso_code' => 'LKR',
                'name' => 'Sri Lankan Rupee',
                'symbol' => 'Rs',
                'decimal_places' => 2,
                'is_active' => true,
            ],
            [
                'iso_code' => 'MYR',
                'name' => 'Malaysian Ringgit',
                'symbol' => 'RM',
                'decimal_places' => 2,
                'is_active' => true,
            ],
            [
                'iso_code' => 'THB',
                'name' => 'Thai Baht',
                'symbol' => '฿',
                'decimal_places' => 2,
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return list<array{code: string, name: string, country_iso_code: string, currency_iso_code: string, is_active: bool}>
     */
    private static function markets(): array
    {
        return [
            [
                'code' => 'lk',
                'name' => 'Sri Lanka',
                'country_iso_code' => 'LK',
                'currency_iso_code' => 'LKR',
                'is_active' => true,
            ],
            [
                'code' => 'my',
                'name' => 'Malaysia',
                'country_iso_code' => 'MY',
                'currency_iso_code' => 'MYR',
                'is_active' => true,
            ],
            [
                'code' => 'th',
                'name' => 'Thailand',
                'country_iso_code' => 'TH',
                'currency_iso_code' => 'THB',
                'is_active' => false,
            ],
        ];
    }
}
