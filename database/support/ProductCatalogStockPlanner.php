<?php

namespace Database\Support;

class ProductCatalogStockPlanner
{
    /**
     * @return list<array{
     *     received_quantity: int,
     *     damaged_quantity: int,
     *     days_ago: int
     * }>
     */
    public static function planGrnReceipts(int $variantIndex, float $unitCost): array
    {
        $targetRemaining = self::targetRemainingStock($variantIndex);
        $grnCount = self::grnCount($variantIndex);

        if ($targetRemaining === 0) {
            return self::planOutOfStockReceipts($grnCount);
        }

        return self::distributeAcrossGrns($targetRemaining, $grnCount, $variantIndex);
    }

    public static function targetRemainingStock(int $variantIndex): int
    {
        $bucket = $variantIndex % 20;

        return match (true) {
            $bucket < 2 => 0,
            $bucket < 6 => 1 + (int) (self::ratio($variantIndex) * 7),
            $bucket < 15 => 25 + (int) (self::ratio($variantIndex + 1) * 70),
            default => 120 + (int) (self::ratio($variantIndex + 2) * 380),
        };
    }

    public static function grnCount(int $variantIndex): int
    {
        return match ($variantIndex % 7) {
            0, 1, 2 => 1,
            3, 4 => 2,
            default => 3,
        };
    }

    /**
     * @return list<array{received_quantity: int, damaged_quantity: int, days_ago: int}>
     */
    private static function planOutOfStockReceipts(int $grnCount): array
    {
        $receipts = [];

        for ($i = 0; $i < $grnCount; $i++) {
            $received = 20 + (int) (self::ratio($i + 3) * 60);
            $damaged = $i === ($grnCount - 1)
                ? $received
                : (int) floor($received * 0.2 * self::ratio($i + 5));

            $receipts[] = [
                'received_quantity' => $received,
                'damaged_quantity' => $damaged,
                'days_ago' => self::daysAgoForGrn($i, $grnCount),
            ];
        }

        return $receipts;
    }

    /**
     * @return list<array{received_quantity: int, damaged_quantity: int, days_ago: int}>
     */
    private static function distributeAcrossGrns(int $targetRemaining, int $grnCount, int $variantIndex): array
    {
        $receipts = [];
        $allocated = 0;

        for ($i = 0; $i < $grnCount; $i++) {
            $isLast = $i === ($grnCount - 1);
            $remainingGrns = $grnCount - $i;

            if ($isLast) {
                $netNeeded = max(0, $targetRemaining - $allocated);
                $damaged = ($variantIndex + $i) % 4 === 0
                    ? max(1, (int) floor($netNeeded * 0.08 * (0.5 + self::ratio($variantIndex + $i))))
                    : 0;
                $received = $netNeeded + $damaged;
            } else {
                $share = (int) ceil($targetRemaining / $remainingGrns);
                $share = max(5, $share + (int) ((self::ratio($variantIndex + $i) * 11) - 3));
                $damaged = ($variantIndex + $i) % 5 === 0
                    ? (int) floor($share * 0.1 * self::ratio($variantIndex + $i + 2))
                    : 0;
                $received = $share + $damaged;
            }

            $allocated += max(0, $received - $damaged);

            $receipts[] = [
                'received_quantity' => max(1, $received),
                'damaged_quantity' => min($damaged, max(0, $received - 1)),
                'days_ago' => self::daysAgoForGrn($i, $grnCount),
            ];
        }

        return $receipts;
    }

    private static function daysAgoForGrn(int $grnIndex, int $grnCount): int
    {
        $base = match ($grnCount) {
            1 => 3 + (int) (self::ratio($grnIndex + 9) * 42),
            2 => [45 + (int) (self::ratio($grnIndex + 10) * 75), 3 + (int) (self::ratio($grnIndex + 11) * 17)][$grnIndex] ?? 10 + (int) (self::ratio($grnIndex + 12) * 50),
            default => [
                90 + (int) (self::ratio($grnIndex + 13) * 90),
                30 + (int) (self::ratio($grnIndex + 14) * 45),
                3 + (int) (self::ratio($grnIndex + 15) * 11),
            ][$grnIndex] ?? 7 + (int) (self::ratio($grnIndex + 16) * 83),
        };

        return max(1, $base);
    }

    private static function ratio(int $seed): float
    {
        return (crc32('stock-seed-'.$seed) % 1000) / 1000;
    }
}
