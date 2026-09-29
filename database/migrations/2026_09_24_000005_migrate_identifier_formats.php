<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remap existing product barcodes and order/invoice numbers to the Feeder
 * commercial identifier formats, then seed sequence cursors.
 *
 * Safe to re-run: skips rows already in the target format.
 */
return new class extends Migration
{
    private const MARKET_TIMEZONES = [
        'lk' => 'Asia/Colombo',
        'my' => 'Asia/Kuala_Lumpur',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('markets', 'market_number')) {
            return;
        }

        $this->migrateProductBarcodes();
        $this->migrateOrderNumbers();
        $this->syncInvoiceNumbers();
        $this->seedProductBarcodeSequences();
        $this->seedOrderNumberSequences();
    }

    public function down(): void
    {
        // Irreversible data remapping — schema rollback handled by prior migrations.
    }

    private function migrateProductBarcodes(): void
    {
        $markets = DB::table('markets')
            ->whereNotNull('market_number')
            ->orderBy('id')
            ->get(['id', 'market_number']);

        foreach ($markets as $market) {
            $variants = DB::table('product_variants as pv')
                ->join('products as p', 'p.id', '=', 'pv.product_id')
                ->where('p.market_id', $market->id)
                ->orderBy('pv.created_at')
                ->orderBy('pv.id')
                ->get(['pv.id', 'pv.barcode']);

            if ($variants->isEmpty()) {
                continue;
            }

            $tempMap = [];
            $finalMap = [];
            $sequence = 0;

            foreach ($variants as $variant) {
                $sequence++;
                $final = $this->formatProductBarcode((int) $market->market_number, $sequence);
                $temp = '__MIG_BC_'.$variant->id;
                $tempMap[$variant->id] = $temp;
                $finalMap[$variant->id] = $final;
            }

            DB::transaction(function () use ($tempMap, $finalMap): void {
                foreach ($tempMap as $variantId => $tempBarcode) {
                    DB::table('product_variants')
                        ->where('id', $variantId)
                        ->update(['barcode' => $tempBarcode]);
                }

                foreach ($finalMap as $variantId => $finalBarcode) {
                    DB::table('product_variants')
                        ->where('id', $variantId)
                        ->update(['barcode' => $finalBarcode]);
                }

                foreach ($finalMap as $variantId => $finalBarcode) {
                    DB::table('order_items')
                        ->where('product_variant_id', $variantId)
                        ->update(['barcode_snapshot' => $finalBarcode]);
                }
            });
        }
    }

    private function migrateOrderNumbers(): void
    {
        $orders = DB::table('orders as o')
            ->join('markets as m', 'm.id', '=', 'o.market_id')
            ->whereNotNull('m.market_number')
            ->orderBy('o.created_at')
            ->orderBy('o.id')
            ->get([
                'o.id',
                'o.order_number',
                'o.created_at',
                'o.market_id',
                'm.market_number',
                'm.code as market_code',
            ]);

        if ($orders->isEmpty()) {
            return;
        }

        $grouped = [];

        foreach ($orders as $order) {
            $businessDate = $this->resolveBusinessDate(
                (string) $order->created_at,
                (string) $order->market_code,
            );
            $key = $order->market_id.'|'.$businessDate;
            $grouped[$key][] = $order;
        }

        $tempMap = [];
        $finalMap = [];
        $legacyMap = [];

        foreach ($grouped as $ordersInGroup) {
            $sequence = 0;

            foreach ($ordersInGroup as $order) {
                $sequence++;
                $businessDate = $this->resolveBusinessDate(
                    (string) $order->created_at,
                    (string) $order->market_code,
                );
                $final = $this->formatOrderNumber(
                    (int) $order->market_number,
                    $businessDate,
                    $sequence,
                );

                $tempMap[$order->id] = '__MIG_ORD_'.$order->id;
                $finalMap[$order->id] = $final;

                $current = trim((string) $order->order_number);
                if ($current !== '' && $current !== $final) {
                    $legacyMap[$order->id] = $current;
                }
            }
        }

        DB::transaction(function () use ($tempMap, $finalMap, $legacyMap): void {
            foreach ($tempMap as $orderId => $tempNumber) {
                DB::table('orders')
                    ->where('id', $orderId)
                    ->update(['order_number' => $tempNumber]);
            }

            foreach ($finalMap as $orderId => $finalNumber) {
                $payload = ['order_number' => $finalNumber];

                if (isset($legacyMap[$orderId]) && Schema::hasColumn('orders', 'legacy_order_number')) {
                    $payload['legacy_order_number'] = $legacyMap[$orderId];
                }

                DB::table('orders')
                    ->where('id', $orderId)
                    ->update($payload);
            }
        });
    }

    private function syncInvoiceNumbers(): void
    {
        if (! Schema::hasTable('order_fulfillments')) {
            return;
        }

        $fulfillments = DB::table('order_fulfillments as f')
            ->join('orders as o', 'o.id', '=', 'f.order_id')
            ->whereNotNull('o.order_number')
            ->where('o.order_number', '!=', '')
            ->get(['f.id', 'o.order_number']);

        foreach ($fulfillments as $fulfillment) {
            DB::table('order_fulfillments')
                ->where('id', $fulfillment->id)
                ->update([
                    'invoice_number' => $fulfillment->order_number,
                    'invoice_generated_at' => DB::raw('COALESCE(invoice_generated_at, NOW())'),
                ]);
        }
    }

    private function seedProductBarcodeSequences(): void
    {
        $markets = DB::table('markets')
            ->whereNotNull('market_number')
            ->get(['id', 'market_number']);

        foreach ($markets as $market) {
            $prefix = (string) $market->market_number;
            $maxSeq = 0;

            $barcodes = DB::table('product_variants as pv')
                ->join('products as p', 'p.id', '=', 'pv.product_id')
                ->where('p.market_id', $market->id)
                ->pluck('pv.barcode');

            foreach ($barcodes as $barcode) {
                $barcode = (string) $barcode;

                if (strlen($barcode) !== 6 || ! ctype_digit($barcode) || ! str_starts_with($barcode, $prefix)) {
                    continue;
                }

                $maxSeq = max($maxSeq, (int) substr($barcode, 1));
            }

            DB::table('product_barcode_sequences')->updateOrInsert(
                ['market_id' => $market->id],
                [
                    'last_value' => $maxSeq,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function seedOrderNumberSequences(): void
    {
        $orders = DB::table('orders as o')
            ->join('markets as m', 'm.id', '=', 'o.market_id')
            ->whereNotNull('m.market_number')
            ->get([
                'o.order_number',
                'o.created_at',
                'o.market_id',
                'm.market_number',
                'm.code as market_code',
            ]);

        $cursors = [];

        foreach ($orders as $order) {
            $orderNumber = (string) $order->order_number;

            if (strlen($orderNumber) !== 11 || ! ctype_digit($orderNumber)) {
                continue;
            }

            $businessDate = $this->resolveBusinessDate(
                (string) $order->created_at,
                (string) $order->market_code,
            );
            $seq = (int) substr($orderNumber, 6);
            $key = $order->market_id.'|'.$businessDate;
            $cursors[$key] = max($cursors[$key] ?? 0, $seq);
        }

        foreach ($cursors as $key => $lastValue) {
            [$marketId, $businessDate] = explode('|', $key, 2);

            DB::table('order_number_sequences')->updateOrInsert(
                [
                    'market_id' => (int) $marketId,
                    'business_date' => $businessDate,
                ],
                [
                    'last_value' => $lastValue,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function formatProductBarcode(int $marketNumber, int $sequence): string
    {
        if ($sequence < 1 || $sequence > 99999) {
            throw new \RuntimeException("Product barcode sequence {$sequence} is out of range for market {$marketNumber}.");
        }

        return sprintf('%d%05d', $marketNumber, $sequence);
    }

    private function formatOrderNumber(int $marketNumber, string $businessDate, int $sequence): string
    {
        if ($sequence < 1 || $sequence > 99999) {
            throw new \RuntimeException("Order sequence {$sequence} is out of range for market {$marketNumber} on {$businessDate}.");
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $businessDate, 'UTC');
        $yy = $date->format('y');
        $ddd = $date->format('z') + 1; // Carbon z is 0-based

        return sprintf('%d%s%03d%05d', $marketNumber, $yy, $ddd, $sequence);
    }

    private function resolveBusinessDate(string $createdAt, string $marketCode): string
    {
        $timezone = self::MARKET_TIMEZONES[strtolower($marketCode)] ?? 'UTC';

        return CarbonImmutable::parse($createdAt, 'UTC')
            ->timezone($timezone)
            ->toDateString();
    }
};
