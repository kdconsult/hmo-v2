<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Livewire\RegisterTenant;
use App\Mail\WelcomeTenant;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ViesValidationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    Plan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'billing_period' => null,
        'max_users' => 2,
        'max_documents' => 100,
        'is_active' => true,
        'sort_order' => 0,
    ]);
});

// --- Page renders ---

test('/register route renders the component', function () {
    $this->get('/register')->assertOk();
});

test('registration form starts on step 1', function () {
    Livewire::test(RegisterTenant::class)
        ->assertSet('step', 1);
});

// --- Step 1 validation ---

test('step 1 requires all account fields', function () {
    Livewire::test(RegisterTenant::class)
        ->call('nextStep')
        ->assertHasErrors(['name' => 'required', 'email' => 'required', 'password' => 'required']);
});

test('step 1 rejects duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(RegisterTenant::class)
        ->set('name', 'Test User')
        ->set('email', 'taken@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->assertHasErrors(['email' => 'unique']);
});

test('step 1 requires password confirmation', function () {
    Livewire::test(RegisterTenant::class)
        ->set('name', 'Test User')
        ->set('email', 'new@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'different')
        ->call('nextStep')
        ->assertHasErrors(['password' => 'confirmed']);
});

test('valid step 1 advances to step 2', function () {
    Livewire::test(RegisterTenant::class)
        ->set('name', 'Test User')
        ->set('email', 'user@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->assertSet('step', 2)
        ->assertHasNoErrors();
});

// --- Step 2 validation ---

test('step 2 requires company name', function () {
    $component = Livewire::test(RegisterTenant::class)
        ->set('name', 'Test User')
        ->set('email', 'user2@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep');

    $component->call('nextStep')
        ->assertHasErrors(['company_name' => 'required'])
        ->assertHasNoErrors(['slug']);
});

test('step 2 requires eik', function () {
    Livewire::test(RegisterTenant::class)
        ->set('name', 'Test User')
        ->set('email', 'user3@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'Test Co')
        ->set('country_code', 'BG')
        ->call('nextStep')
        ->assertHasErrors(['eik' => 'required']);
});

test('updating country_code auto-fills currency and timezone', function () {
    Livewire::test(RegisterTenant::class)
        ->set('country_code', 'DE')
        ->assertSet('currency_code', 'EUR')
        ->assertSet('timezone', 'Europe/Berlin')
        ->assertSet('locale', 'de_DE');
});

test('Bulgaria country_code sets EUR currency', function () {
    Livewire::test(RegisterTenant::class)
        ->set('country_code', 'BG')
        ->assertSet('currency_code', 'EUR');
});

// --- Full registration submit ---

test('submit creates user, tenant, and domain', function () {
    Mail::fake();

    $freePlan = Plan::where('slug', 'free')->first();

    Livewire::test(RegisterTenant::class)
        ->set('name', 'John Doe')
        ->set('email', 'john@acme.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'Acme Corp')
        ->set('country_code', 'BG')
        ->set('eik', '111111111')
        ->call('nextStep')
        ->call('nextStep') // VAT step skipped
        ->set('plan_id', $freePlan->id)
        ->call('submit');

    expect(User::where('email', 'john@acme.com')->exists())->toBeTrue();

    $tenant = Tenant::where('name', 'Acme Corp')->first();

    expect($tenant)->not->toBeNull()
        ->and($tenant->slug)->toMatch('/^[a-z]+-[a-z]+(-\d+)?$/')
        ->and($tenant->domains()->where('domain', $tenant->slug)->exists())->toBeTrue()
        ->and($tenant->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($tenant->trial_ends_at)->not->toBeNull()
        ->and($tenant->plan_id)->toBe($freePlan->id)
        ->and($tenant->default_currency_code)->toBe('EUR')
        ->and($tenant->vat_number)->toBeNull()
        ->and($tenant->is_vat_registered)->toBeFalse();
});

test('submit sends welcome email', function () {
    Mail::fake();

    $freePlan = Plan::where('slug', 'free')->first();

    Livewire::test(RegisterTenant::class)
        ->set('name', 'Jane Doe')
        ->set('email', 'jane@newco.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'New Co')
        ->set('country_code', 'DE')
        ->set('eik', '222222222')
        ->call('nextStep')
        ->call('nextStep') // VAT step skipped
        ->set('plan_id', $freePlan->id)
        ->call('submit');

    Mail::assertSent(WelcomeTenant::class, fn ($mail) => $mail->hasTo('jane@newco.com'));
});

// --- S-4: Rate limiting ---

test('submit is blocked after 5 attempts from the same IP', function () {
    Mail::fake();
    $freePlan = Plan::where('slug', 'free')->first();

    // Exhaust the 5-attempt limit
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit('tenant-registration:127.0.0.1', 60);
    }

    Livewire::test(RegisterTenant::class)
        ->set('name', 'Rate Test')
        ->set('email', 'ratetest@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'Rate Co')
        ->set('country_code', 'BG')
        ->set('eik', '444444444')
        ->call('nextStep')
        ->call('nextStep') // VAT step skipped
        ->set('plan_id', $freePlan->id)
        ->call('submit')
        ->assertHasErrors(['email']);

    expect(User::where('email', 'ratetest@example.com')->exists())->toBeFalse();
});

test('submit attaches user to tenant central pivot', function () {
    Mail::fake();

    $freePlan = Plan::where('slug', 'free')->first();

    Livewire::test(RegisterTenant::class)
        ->set('name', 'Bob')
        ->set('email', 'bob@mycompany.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'My Company')
        ->set('country_code', 'BG')
        ->set('eik', '333333333')
        ->call('nextStep')
        ->call('nextStep') // VAT step skipped
        ->set('plan_id', $freePlan->id)
        ->call('submit');

    $user = User::where('email', 'bob@mycompany.com')->first();
    $tenant = Tenant::where('name', 'My Company')->first();

    expect($tenant->users()->where('user_id', $user->id)->exists())->toBeTrue();
});

// --- VAT step (Step 3) ---

test('registration form no longer accepts a raw vat_number field', function () {
    $component = Livewire::test(RegisterTenant::class);

    // vat_number was removed — setting it should do nothing (property doesn't exist)
    expect(fn () => $component->set('vat_number', 'BG123456789'))
        ->toThrow(PublicPropertyNotFoundException::class);
});

test('VAT step can be skipped — tenant created with is_vat_registered false', function () {
    Mail::fake();
    $freePlan = Plan::where('slug', 'free')->first();

    Livewire::test(RegisterTenant::class)
        ->set('name', 'Skip VAT')
        ->set('email', 'skipvat@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'Skip Co')
        ->set('country_code', 'BG')
        ->set('eik', '555555555')
        ->call('nextStep')
        ->call('nextStep') // skip VAT step
        ->set('plan_id', $freePlan->id)
        ->call('submit');

    $tenant = Tenant::where('name', 'Skip Co')->first();
    expect($tenant->is_vat_registered)->toBeFalse()
        ->and($tenant->vat_number)->toBeNull();
});

test('VAT step VIES valid → tenant created with confirmed VAT', function () {
    Mail::fake();

    $mock = Mockery::mock(ViesValidationService::class);
    $mock->shouldReceive('validate')
        ->once()
        ->andReturn([
            'available' => true,
            'valid' => true,
            'name' => 'Test GmbH',
            'address' => 'Hauptstr. 1, Berlin',
            'country_code' => 'DE',
            'vat_number' => '123456789',
            'request_id' => 'req-001',
        ]);
    app()->instance(ViesValidationService::class, $mock);

    $freePlan = Plan::where('slug', 'free')->first();

    Livewire::test(RegisterTenant::class)
        ->set('name', 'Vies User')
        ->set('email', 'vies@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('nextStep')
        ->set('company_name', 'Test GmbH')
        ->set('country_code', 'DE')
        ->set('eik', '666666666')
        ->call('nextStep')
        ->set('isVatRegistered', true)
        ->set('vatLookup', '123456789')
        ->call('checkVies')
        ->assertSet('vatCheckType', 'success')
        ->assertSet('confirmedVatNumber', 'DE123456789')
        ->call('nextStep')
        ->set('plan_id', $freePlan->id)
        ->call('submit');

    $tenant = Tenant::where('name', 'Test GmbH')->first();
    expect($tenant->is_vat_registered)->toBeTrue()
        ->and($tenant->vat_number)->toBe('DE123456789')
        ->and($tenant->vies_verified_at)->not->toBeNull();
});

test('VAT step VIES invalid → vatCheckMessage shown, confirmedVatNumber stays empty', function () {
    $mock = Mockery::mock(ViesValidationService::class);
    $mock->shouldReceive('validate')
        ->once()
        ->andReturn([
            'available' => true,
            'valid' => false,
            'name' => null,
            'address' => null,
            'country_code' => 'BG',
            'vat_number' => '000000000',
            'request_id' => null,
        ]);
    app()->instance(ViesValidationService::class, $mock);

    Livewire::test(RegisterTenant::class)
        ->set('country_code', 'BG')
        ->set('isVatRegistered', true)
        ->set('vatLookup', '000000000')
        ->call('checkVies')
        ->assertSet('vatCheckType', 'danger')
        ->assertSet('confirmedVatNumber', '');
});

test('DB CHECK constraint prevents is_vat_registered=true with null vat_number', function () {
    $tenant = Tenant::factory()->create(['is_vat_registered' => false, 'vat_number' => null]);

    expect(fn () => $tenant->update(['is_vat_registered' => true]))
        ->toThrow(QueryException::class);
});
