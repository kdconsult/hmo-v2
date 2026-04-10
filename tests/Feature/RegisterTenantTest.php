<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Livewire\RegisterTenant;
use App\Mail\WelcomeTenant;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
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
        ->and($tenant->default_currency_code)->toBe('EUR');
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
        ->set('plan_id', $freePlan->id)
        ->call('submit');

    Mail::assertSent(WelcomeTenant::class, fn ($mail) => $mail->hasTo('jane@newco.com'));
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
        ->set('plan_id', $freePlan->id)
        ->call('submit');

    $user = User::where('email', 'bob@mycompany.com')->first();
    $tenant = Tenant::where('name', 'My Company')->first();

    expect($tenant->users()->where('user_id', $user->id)->exists())->toBeTrue();
});
