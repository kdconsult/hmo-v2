<?php

declare(strict_types=1);

use App\Enums\VatScenario;
use App\Models\Partner;

it('DomesticExempt enum case exists', function () {
    expect(VatScenario::tryFrom('domestic_exempt'))->toBe(VatScenario::DomesticExempt);
});

it('requires rate change', function () {
    expect(VatScenario::DomesticExempt->requiresVatRateChange())->toBeTrue();
});

it('determine() never returns DomesticExempt', function () {
    // ->make() — no DB touch; determine() only reads country_code, hasValidEuVat(), $partner->id
    $partner = Partner::factory()->make(['country_code' => 'BG']);

    $result = VatScenario::determine($partner, 'BG', tenantIsVatRegistered: true);

    expect($result)->toBe(VatScenario::Domestic)
        ->and($result)->not->toBe(VatScenario::DomesticExempt);
});
