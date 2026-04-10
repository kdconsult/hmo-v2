<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Filament\Landlord\Resources\Tenants\Pages\ViewTenant;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    Filament::setCurrentPanel(Filament::getPanel('landlord'));
    $this->landlord = User::factory()->create(['is_landlord' => true]);
    $this->actingAs($this->landlord);
});

// --- Page loads ---

test('view tenant page loads for landlord', function () {
    $tenant = Tenant::factory()->create();

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->assertOk();
});

// --- Action visibility by status ---

test('suspend action is visible for active tenant', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->assertActionVisible('suspend');
});

test('suspend action is hidden for suspended tenant', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->assertActionHidden('suspend');
});

test('markForDeletion action is visible for suspended tenant', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->assertActionVisible('markForDeletion');
});

test('reactivate action is visible for suspended tenant', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->assertActionVisible('reactivate');
});

test('billing actions are hidden for landlord tenant', function () {
    $tenant = Tenant::factory()->create();
    config(['hmo.landlord_tenant_id' => $tenant->id]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->assertActionHidden('changePlan')
        ->assertActionHidden('cancelSubscription')
        ->assertActionHidden('recordPayment')
        ->assertActionHidden('sendProformaInvoice');
});

// --- Action execution ---

test('suspend action transitions active tenant to suspended', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('suspend', ['reason' => 'non_payment'])
        ->assertHasNoErrors();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});

test('reactivate action restores suspended tenant to active', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('reactivate')
        ->assertHasNoErrors();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

test('markForDeletion action transitions suspended tenant', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('markForDeletion')
        ->assertHasNoErrors();

    expect($tenant->fresh()->status)->toBe(TenantStatus::MarkedForDeletion);
});

test('changePlan action updates tenant plan', function () {
    $oldPlan = Plan::create([
        'name' => 'Starter', 'slug' => 'view-starter-'.uniqid(),
        'price' => 9.00, 'billing_period' => 'monthly',
        'is_active' => true, 'sort_order' => 1,
    ]);
    $newPlan = Plan::create([
        'name' => 'Pro', 'slug' => 'view-pro-'.uniqid(),
        'price' => 29.00, 'billing_period' => 'monthly',
        'is_active' => true, 'sort_order' => 2,
    ]);
    $tenant = Tenant::factory()->create([
        'plan_id' => $oldPlan->id,
        'subscription_status' => SubscriptionStatus::Active,
    ]);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('changePlan', ['plan_id' => $newPlan->id])
        ->assertHasNoErrors()
        ->assertNotified();

    expect($tenant->fresh()->plan_id)->toBe($newPlan->id);
});
