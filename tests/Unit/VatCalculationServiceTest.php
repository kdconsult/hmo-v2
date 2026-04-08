<?php

use App\Enums\PricingMode;
use App\Services\VatCalculationService;

beforeEach(function () {
    $this->service = new VatCalculationService;
});

test('fromNet calculates VAT from exclusive amount', function () {
    $result = $this->service->fromNet(100.00, 20.00);

    expect($result['net'])->toBe(100.00)
        ->and($result['vat'])->toBe(20.00)
        ->and($result['gross'])->toBe(120.00)
        ->and($result['rate'])->toBe(20.00);
});

test('fromGross extracts VAT from inclusive amount', function () {
    $result = $this->service->fromGross(120.00, 20.00);

    expect($result['net'])->toBe(100.00)
        ->and($result['vat'])->toBe(20.00)
        ->and($result['gross'])->toBe(120.00);
});

test('calculate delegates to fromNet for VatExclusive mode', function () {
    $result = $this->service->calculate(100.00, 9.00, PricingMode::VatExclusive);

    expect($result['gross'])->toBe(109.00)
        ->and($result['vat'])->toBe(9.00);
});

test('calculate delegates to fromGross for VatInclusive mode', function () {
    $result = $this->service->calculate(109.00, 9.00, PricingMode::VatInclusive);

    expect($result['gross'])->toBe(109.00)
        ->and($result['net'])->toBe(100.00);
});

test('calculateDocument sums multiple lines', function () {
    $lines = [
        ['amount' => 100.00, 'rate' => 20.00, 'mode' => PricingMode::VatExclusive],
        ['amount' => 50.00, 'rate' => 9.00, 'mode' => PricingMode::VatExclusive],
    ];

    $result = $this->service->calculateDocument($lines);

    expect($result['net'])->toBe(150.00)
        ->and($result['vat'])->toBe(24.50)
        ->and($result['gross'])->toBe(174.50);
});

test('calculateDocument provides VAT breakdown by rate', function () {
    $lines = [
        ['amount' => 100.00, 'rate' => 20.00, 'mode' => PricingMode::VatExclusive],
        ['amount' => 50.00, 'rate' => 20.00, 'mode' => PricingMode::VatExclusive],
        ['amount' => 200.00, 'rate' => 9.00, 'mode' => PricingMode::VatExclusive],
    ];

    $result = $this->service->calculateDocument($lines);

    expect($result['vat_breakdown'])->toHaveKey('20.00%')
        ->and($result['vat_breakdown'])->toHaveKey('9.00%')
        ->and($result['vat_breakdown']['20.00%'])->toBe(30.00)
        ->and($result['vat_breakdown']['9.00%'])->toBe(18.00);
});

test('fromNet handles zero VAT rate', function () {
    $result = $this->service->fromNet(500.00, 0.00);

    expect($result['vat'])->toBe(0.00)
        ->and($result['gross'])->toBe(500.00);
});
