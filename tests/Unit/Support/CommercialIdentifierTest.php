<?php

namespace Tests\Unit\Support;

use Feeder\Core\Support\CommercialIdentifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CommercialIdentifierTest extends TestCase
{
    public function test_product_barcode_format(): void
    {
        $this->assertSame('100001', CommercialIdentifier::formatProductBarcode(1, 1));
        $this->assertSame('200002', CommercialIdentifier::formatProductBarcode(2, 2));
        $this->assertTrue(CommercialIdentifier::isValidProductBarcodeForMarket('100146', 1));
        $this->assertFalse(CommercialIdentifier::isValidProductBarcodeForMarket('200146', 1));
        $this->assertFalse(CommercialIdentifier::isValidProductBarcodeForMarket('10014', 1));
    }

    public function test_order_number_format(): void
    {
        $this->assertSame('12620000482', CommercialIdentifier::formatOrderNumber(1, 26, 200, 482));
        $this->assertSame('22620000482', CommercialIdentifier::formatOrderNumber(2, 26, 200, 482));
        $this->assertTrue(CommercialIdentifier::isValidOrderNumberForMarket('12620000482', 1));
        $this->assertFalse(CommercialIdentifier::isValidOrderNumberForMarket('12620000482', 2));
        $this->assertFalse(CommercialIdentifier::isValidOrderNumberForMarket('12600000482', 1));
    }

    public function test_legacy_order_number_detection(): void
    {
        $this->assertTrue(CommercialIdentifier::isLegacyOrderNumber('ORD-2026-000568'));
        $this->assertFalse(CommercialIdentifier::isLegacyOrderNumber('12620000482'));
    }

    public function test_sequence_bounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommercialIdentifier::formatProductBarcode(1, 100000);
    }
}
