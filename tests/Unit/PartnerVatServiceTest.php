<?php

declare(strict_types=1);

use App\Enums\VatStatus;
use App\Models\Partner;
use App\Services\PartnerVatService;
use App\Services\ViesValidationService;

function mockViesService(bool $available, bool $valid, string $vatNumber = '123456789'): ViesValidationService
{
    $mock = Mockery::mock(ViesValidationService::class);
    $mock->shouldReceive('validate')->andReturn([
        'available' => $available,
        'valid' => $valid,
        'name' => $valid ? 'Test Partner GmbH' : null,
        'address' => $valid ? 'Berlin, Germany' : null,
        'country_code' => 'DE',
        'vat_number' => $vatNumber,
    ]);

    return $mock;
}

afterEach(fn () => Mockery::close());

// --- reVerify ---

test('re-verify: VIES valid sets confirmed and stores vat_number', function () {
    $partner = Mockery::mock(Partner::class)->makePartial();
    $partner->shouldReceive('save')->once();
    $partner->country_code = 'DE';
    $partner->vat_number = 'DE123456789';
    $partner->vat_status = VatStatus::Confirmed;

    $service = new PartnerVatService(mockViesService(available: true, valid: true, vatNumber: '123456789'));
    $result = $service->reVerify($partner);

    expect($result)->toBe(VatStatus::Confirmed)
        ->and($partner->vat_status)->toBe(VatStatus::Confirmed)
        ->and($partner->vat_number)->toBe('DE123456789')
        ->and($partner->vies_verified_at)->not->toBeNull();
});

test('re-verify: VIES invalid sets not_registered and clears vat_number', function () {
    $partner = Mockery::mock(Partner::class)->makePartial();
    $partner->shouldReceive('save')->once();
    $partner->country_code = 'DE';
    $partner->vat_number = 'DE123456789';
    $partner->vat_status = VatStatus::Confirmed;

    $service = new PartnerVatService(mockViesService(available: true, valid: false));
    $result = $service->reVerify($partner);

    expect($result)->toBe(VatStatus::NotRegistered)
        ->and($partner->vat_status)->toBe(VatStatus::NotRegistered)
        ->and($partner->vat_number)->toBeNull()
        ->and($partner->vies_verified_at)->toBeNull();
});

test('re-verify: VIES unavailable leaves status unchanged and updates last_checked_at', function () {
    $partner = Mockery::mock(Partner::class)->makePartial();
    $partner->shouldReceive('save')->once();
    $partner->country_code = 'DE';
    $partner->vat_number = 'DE123456789';
    $partner->vat_status = VatStatus::Confirmed;

    $service = new PartnerVatService(mockViesService(available: false, valid: false));
    $result = $service->reVerify($partner);

    expect($result)->toBe(VatStatus::Confirmed)
        ->and($partner->vat_status)->toBe(VatStatus::Confirmed)
        ->and($partner->vat_number)->toBe('DE123456789')
        ->and($partner->vies_last_checked_at)->not->toBeNull();
});
