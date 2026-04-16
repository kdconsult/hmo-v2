<?php

declare(strict_types=1);

use App\Enums\VatStatus;
use App\Filament\Resources\Partners\Pages\CreatePartner;
use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Filament\Resources\Partners\Pages\ViewPartner;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use App\Services\ViesValidationService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->tenant = Tenant::factory()->create(['country_code' => 'BG']);
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
    $this->actingAs($this->user);

    tenancy()->initialize($this->tenant);

    // Set URL defaults so subdomain route parameter is resolved during tests
    URL::defaults(['subdomain' => $this->tenant->slug]);
});

afterEach(function () {
    tenancy()->end();
    Mockery::close();
});

function mockViesForPartner(bool $available, bool $valid, string $vatNumber = '123456789'): void
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
    app()->instance(ViesValidationService::class, $mock);
}

// --- Happy path ---

test('VIES valid: create partner with confirmed vat_status and stored vat_number', function () {
    mockViesForPartner(available: true, valid: true, vatNumber: '123456789');

    Livewire::test(CreatePartner::class)
        ->set('data.name', 'Test GmbH')
        ->set('data.country_code', 'DE')
        ->set('data.is_vat_registered', true)
        ->set('data.vat_lookup', '123456789')
        ->call('handleViesCheck')
        ->assertNotified('VAT registration confirmed')
        ->call('create')
        ->assertNotified();

    // handleViesCheck pre-fills name from VIES response ('Test Partner GmbH')
    $partner = Partner::where('name', 'Test Partner GmbH')->firstOrFail();

    expect($partner->vat_status)->toBe(VatStatus::Confirmed)
        ->and($partner->vat_number)->toBe('DE123456789')
        ->and($partner->is_vat_registered)->toBeTrue()
        ->and($partner->vies_verified_at)->not->toBeNull();
});

// --- VIES invalid ---

test('VIES invalid: clears vat fields and sets not_registered', function () {
    mockViesForPartner(available: true, valid: false);

    Livewire::test(CreatePartner::class)
        ->set('data.name', 'Bad Partner')
        ->set('data.country_code', 'DE')
        ->set('data.is_vat_registered', true)
        ->set('data.vat_lookup', '000000000')
        ->call('handleViesCheck')
        ->assertNotified('VAT number not found in VIES')
        ->assertSet('data.is_vat_registered', false)
        ->assertSet('data.vat_number', null)
        ->assertSet('data.vat_status', VatStatus::NotRegistered->value);
});

// --- VIES unavailable → pending ---

test('VIES unavailable: partner saves as pending with toggle ON and no vat_number', function () {
    mockViesForPartner(available: false, valid: false);

    Livewire::test(CreatePartner::class)
        ->set('data.name', 'Pending GmbH')
        ->set('data.country_code', 'DE')
        ->set('data.is_vat_registered', true)
        ->set('data.vat_lookup', '123456789')
        ->call('handleViesCheck')
        ->assertNotified('VIES service is unreachable')
        ->assertSet('data.is_vat_registered', true)
        ->assertSet('data.vat_number', null)
        ->assertSet('data.vat_status', VatStatus::Pending->value)
        ->call('create')
        ->assertNotified();

    $partner = Partner::where('name', 'Pending GmbH')->firstOrFail();

    expect($partner->vat_status)->toBe(VatStatus::Pending)
        ->and($partner->vat_number)->toBeNull()
        ->and($partner->is_vat_registered)->toBeTrue()
        ->and($partner->vies_last_checked_at)->not->toBeNull();
});

// --- Save guard ---

test('save is blocked when toggle is ON but no VIES check was run', function () {
    Livewire::test(CreatePartner::class)
        ->set('data.name', 'Guard Test')
        ->set('data.is_vat_registered', true)
        ->set('data.vat_number', null)
        ->set('data.vat_status', VatStatus::NotRegistered->value)
        ->call('create')
        ->assertNotified('VAT verification required');

    expect(Partner::where('name', 'Guard Test')->exists())->toBeFalse();
});

// --- Country change resets ---

test('changing country_code clears vat fields', function () {
    mockViesForPartner(available: true, valid: true, vatNumber: '123456789');

    Livewire::test(CreatePartner::class)
        ->set('data.name', 'Test')
        ->set('data.country_code', 'DE')
        ->set('data.is_vat_registered', true)
        ->set('data.vat_lookup', '123456789')
        ->call('handleViesCheck')
        ->assertSet('data.vat_number', 'DE123456789')
        ->set('data.country_code', 'FR')
        ->assertSet('data.is_vat_registered', false)
        ->assertSet('data.vat_number', null)
        ->assertSet('data.vat_status', VatStatus::NotRegistered->value);
});

// --- Edit loads confirmed partner correctly ---

test('edit loads a confirmed partner with vat fields pre-filled', function () {
    $partner = Partner::factory()->euWithVat('DE')->create(['name' => 'Confirmed Partner']);

    Livewire::test(EditPartner::class, ['record' => $partner->id])
        ->assertSet('data.is_vat_registered', true)
        ->assertSet('data.vat_number', 'DE123456789')
        ->assertSet('data.vat_status', VatStatus::Confirmed->value);
});

// --- Re-verify from ViewPartner ---

test('validate_vat action re-verifies and stays confirmed when VIES valid', function () {
    mockViesForPartner(available: true, valid: true, vatNumber: '123456789');

    $partner = Partner::factory()->euWithVat('DE')->create(['name' => 'Confirmed Partner']);

    Livewire::test(ViewPartner::class, ['record' => $partner->id])
        ->callAction('validate_vat')
        ->assertNotified('VAT confirmed');

    $partner->refresh();
    expect($partner->vat_status)->toBe(VatStatus::Confirmed)
        ->and($partner->vies_last_checked_at)->not->toBeNull();
});

test('validate_vat action sets not_registered when VIES returns invalid', function () {
    mockViesForPartner(available: true, valid: false);

    $partner = Partner::factory()->euWithVat('DE')->create();

    Livewire::test(ViewPartner::class, ['record' => $partner->id])
        ->callAction('validate_vat')
        ->assertNotified('VAT number no longer valid');

    $partner->refresh();
    expect($partner->vat_status)->toBe(VatStatus::NotRegistered)
        ->and($partner->vat_number)->toBeNull();
});

// --- Validate VAT action hidden for pending partners ---

test('validate_vat action is not visible for pending partners', function () {
    $partner = Partner::factory()->vatPending('DE')->create();

    Livewire::test(ViewPartner::class, ['record' => $partner->id])
        ->assertActionHidden('validate_vat');
});

// --- Pending partner allowed to save ---

test('pending partner can be saved without blocking', function () {
    mockViesForPartner(available: false, valid: false);

    Livewire::test(CreatePartner::class)
        ->set('data.name', 'Pending Save Test')
        ->set('data.country_code', 'DE')
        ->set('data.is_vat_registered', true)
        ->set('data.vat_lookup', '123456789')
        ->call('handleViesCheck')
        ->call('create')
        ->assertNotified();

    expect(Partner::where('name', 'Pending Save Test')->where('vat_status', VatStatus::Pending)->exists())->toBeTrue();
});
