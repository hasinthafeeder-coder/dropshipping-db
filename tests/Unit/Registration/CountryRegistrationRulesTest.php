<?php

namespace Tests\Unit\Registration;

use Feeder\Core\Exceptions\UnsupportedRegistrationCountryException;
use Feeder\Core\Registration\CountryRegistrationRules\MalaysiaRegistrationRules;
use Feeder\Core\Registration\CountryRegistrationRules\SriLankaRegistrationRules;
use Feeder\Core\Services\CountryRegistrationRuleService;
use Tests\TestCase;

class CountryRegistrationRulesTest extends TestCase
{
    private SriLankaRegistrationRules $sriLankaRules;

    private MalaysiaRegistrationRules $malaysiaRules;

    private CountryRegistrationRuleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sriLankaRules = new SriLankaRegistrationRules();
        $this->malaysiaRules = new MalaysiaRegistrationRules();
        $this->service = app(CountryRegistrationRuleService::class);
    }

    public function test_sri_lanka_phone_validation_accepts_valid_numbers(): void
    {
        $this->assertTrue($this->sriLankaRules->isValidPhone('0712345678'));
        $this->assertSame('0712345678', $this->sriLankaRules->normalizePhone('94712345678'));
        $this->assertSame('0712345678', $this->sriLankaRules->normalizePhone('+94 71 234 5678'));
    }

    public function test_sri_lanka_phone_validation_rejects_invalid_numbers(): void
    {
        $this->assertFalse($this->sriLankaRules->isValidPhone('12345'));
        $this->assertFalse($this->sriLankaRules->isValidPhone('1712345678'));
        $this->assertNull($this->sriLankaRules->normalizePhone('12345'));
    }

    public function test_malaysia_phone_validation_accepts_supported_formats(): void
    {
        $this->assertTrue($this->malaysiaRules->isValidPhone('0123456789'));
        $this->assertTrue($this->malaysiaRules->isValidPhone('01123456789'));
        $this->assertSame('0123456789', $this->malaysiaRules->normalizePhone('+60123456789'));
        $this->assertSame('0123456789', $this->malaysiaRules->normalizePhone('60123456789'));
        $this->assertSame('01123456789', $this->malaysiaRules->normalizePhone('601123456789'));
    }

    public function test_malaysia_phone_validation_rejects_invalid_numbers(): void
    {
        $this->assertFalse($this->malaysiaRules->isValidPhone('0212345678'));
        $this->assertFalse($this->malaysiaRules->isValidPhone('012345'));
        $this->assertNull($this->malaysiaRules->normalizePhone('0212345678'));
    }

    public function test_country_rules_resolve_by_iso_code_not_database_id(): void
    {
        $rules = $this->service->resolveByIsoCode('LK');

        $this->assertSame('LK', $rules->isoCode());
        $this->assertSame('MY', $this->service->resolveByIsoCode('MY')->isoCode());
    }

    public function test_unknown_country_handling_is_explicit(): void
    {
        $this->expectException(UnsupportedRegistrationCountryException::class);

        $this->service->resolveByIsoCode('SG');
    }

    public function test_valid_old_format_sri_lankan_nic_accepted(): void
    {
        $this->assertTrue($this->sriLankaRules->isValidIdentityDocument('123456789V'));
        $this->assertSame('123456789V', $this->sriLankaRules->normalizeIdentityDocument('123456789v'));
    }

    public function test_valid_new_format_sri_lankan_nic_accepted(): void
    {
        $this->assertTrue($this->sriLankaRules->isValidIdentityDocument('199012345678'));
    }

    public function test_invalid_sri_lankan_nic_rejected(): void
    {
        $this->assertFalse($this->sriLankaRules->isValidIdentityDocument('12345678V'));
        $this->assertFalse($this->sriLankaRules->isValidIdentityDocument('ABCDEFGHIJKL'));
    }

    public function test_valid_malaysian_identity_document_accepted(): void
    {
        $this->assertTrue($this->malaysiaRules->isValidIdentityDocument('900101011234'));
        $this->assertSame('900101011234', $this->malaysiaRules->normalizeIdentityDocument('900101-01-1234'));
    }

    public function test_invalid_malaysian_identity_document_rejected(): void
    {
        $this->assertFalse($this->malaysiaRules->isValidIdentityDocument('999999011234'));
        $this->assertFalse($this->malaysiaRules->isValidIdentityDocument('123456789V'));
    }

    public function test_sri_lankan_and_malaysian_identity_rules_diverge_on_birth_date(): void
    {
        $this->assertFalse($this->malaysiaRules->isValidIdentityDocument('123456789V'));
        $this->assertTrue($this->sriLankaRules->isValidIdentityDocument('123456789V'));
        $this->assertFalse($this->malaysiaRules->isValidIdentityDocument('999999011234'));
        $this->assertTrue($this->sriLankaRules->isValidIdentityDocument('999999011234'));
    }

    public function test_reseller_registration_defaults_to_sri_lanka_rules(): void
    {
        $rules = $this->service->resolveForResellerRegistration();

        $this->assertSame('LK', $rules->isoCode());
        $this->assertTrue($rules->isValidPhone('0771234567'));
    }
}
