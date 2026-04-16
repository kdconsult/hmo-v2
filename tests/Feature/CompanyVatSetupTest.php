<?php

declare(strict_types=1);

use App\Filament\Pages\CompanySettingsPage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use App\Services\ViesValidationService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->tenant = Tenant::factory()->create(['country_code' => 'BG']);
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
    $this->actingAs($this->user);

    tenancy()->initialize($this->tenant);
});

afterEach(function () {
    tenancy()->end();
    Mockery::close();
});

function mockVies(bool $available, bool $valid, string $vatNumber = '123456789'): void
{
    $mock = Mockery::mock(ViesValidationService::class);
    $mock->shouldReceive('validate')->andReturn([
        'available' => $available,
        'valid' => $valid,
        'name' => $valid ? 'Test Company Ltd' : null,
        'address' => $valid ? 'Sofia, Bulgaria' : null,
        'country_code' => 'BG',
        'vat_number' => $vatNumber,
    ]);
    app()->instance(ViesValidationService::class, $mock);
}

// --- Happy path ---

test('VIES check confirms valid VAT and save persists to tenants table', function () {
    mockVies(available: true, valid: true, vatNumber: '123456789');

    Livewire::test(CompanySettingsPage::class)
        ->set('data.company.country_code', 'BG')
        ->set('data.vat.is_vat_registered', true)
        ->set('data.vat.vat_lookup', '123456789')
        ->call('handleViesCheck')
        ->assertNotified('VAT registration confirmed')
        ->call('save')
        ->assertNotified('Settings saved');

    $this->tenant->refresh();

    expect($this->tenant->is_vat_registered)->toBeTrue()
        ->and($this->tenant->vat_number)->toBe('BG123456789')
        ->and($this->tenant->vies_verified_at)->not->toBeNull();
});

// --- VIES invalid ---

test('VIES check shows warning and clears VAT fields when number is not valid', function () {
    mockVies(available: true, valid: false);

    Livewire::test(CompanySettingsPage::class)
        ->set('data.company.country_code', 'BG')
        ->set('data.vat.is_vat_registered', true)
        ->set('data.vat.vat_lookup', '000000000')
        ->call('handleViesCheck')
        ->assertNotified('VAT number not found in VIES')
        ->assertSet('data.vat.is_vat_registered', false)
        ->assertSet('data.vat.vat_number', null);
});

// --- VIES unreachable ---

test('VIES check resets toggle and clears VAT when service is unreachable', function () {
    mockVies(available: false, valid: false);

    Livewire::test(CompanySettingsPage::class)
        ->set('data.company.country_code', 'BG')
        ->set('data.vat.is_vat_registered', true)
        ->set('data.vat.vat_lookup', '123456789')
        ->call('handleViesCheck')
        ->assertNotified('VIES service is unreachable')
        ->assertSet('data.vat.is_vat_registered', false)
        ->assertSet('data.vat.vat_number', null);

    $this->tenant->refresh();
    expect($this->tenant->is_vat_registered)->toBeFalse();
});

// --- Save guard ---

test('save is blocked when is_vat_registered is true but vat_number is not confirmed', function () {
    Livewire::test(CompanySettingsPage::class)
        ->set('data.vat.is_vat_registered', true)
        ->set('data.vat.vat_number', null)
        ->call('save')
        ->assertNotified('VAT verification required');

    $this->tenant->refresh();
    expect($this->tenant->is_vat_registered)->toBeFalse();
});
