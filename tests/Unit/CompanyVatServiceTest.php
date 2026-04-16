<?php

use App\Models\Tenant;
use App\Services\CompanyVatService;
use Mockery\MockInterface;

function makeVatService(): CompanyVatService
{
    return new CompanyVatService;
}

function mockTenant(): MockInterface&Tenant
{
    /** @var MockInterface&Tenant $mock */
    $mock = Mockery::mock(Tenant::class)->makePartial();
    $mock->shouldReceive('save')->once()->andReturnSelf();

    return $mock;
}

test('updateVatRegistration saves vat_number and sets vies_verified_at when registering', function () {
    $tenant = mockTenant();

    makeVatService()->updateVatRegistration($tenant, [
        'is_vat_registered' => true,
        'vat_number' => 'BG123456789',
        'country_code' => 'BG',
    ]);

    expect($tenant->is_vat_registered)->toBeTrue()
        ->and($tenant->vat_number)->toBe('BG123456789')
        ->and($tenant->vies_verified_at)->not->toBeNull()
        ->and($tenant->country_code)->toBe('BG');
});

test('updateVatRegistration clears vat_number and vies_verified_at when deregistering', function () {
    $tenant = mockTenant();
    $tenant->vat_number = 'BG123456789';
    $tenant->vies_verified_at = now();

    makeVatService()->updateVatRegistration($tenant, [
        'is_vat_registered' => false,
        'vat_number' => null,
        'country_code' => 'BG',
    ]);

    expect($tenant->is_vat_registered)->toBeFalse()
        ->and($tenant->vat_number)->toBeNull()
        ->and($tenant->vies_verified_at)->toBeNull();
});

test('updateVatRegistration throws when is_vat_registered is true but vat_number is null', function () {
    $tenant = Mockery::mock(Tenant::class)->makePartial();
    $tenant->shouldReceive('save')->never();

    expect(
        fn () => makeVatService()->updateVatRegistration($tenant, [
            'is_vat_registered' => true,
            'vat_number' => null,
            'country_code' => 'BG',
        ])
    )->toThrow(\InvalidArgumentException::class);
});

test('updateVatRegistration updates country_code on tenant', function () {
    $tenant = mockTenant();

    makeVatService()->updateVatRegistration($tenant, [
        'is_vat_registered' => false,
        'vat_number' => null,
        'country_code' => 'DE',
    ]);

    expect($tenant->country_code)->toBe('DE');
});
