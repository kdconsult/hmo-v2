<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use App\Services\TenantDeletionGuard;

function makeLandlord(): User
{
    return User::factory()->create(['is_landlord' => true]);
}

function makeTenant(array $attributes = []): Tenant
{
    return Tenant::factory()->create($attributes);
}

// --- Default status ---

test('tenant is created with active status by default', function () {
    $tenant = makeTenant();

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->isActive())->toBeTrue()
        ->and($tenant->isSuspended())->toBeFalse()
        ->and($tenant->isPendingDeletion())->toBeFalse();
});

// --- suspend() ---

test('suspend transitions active tenant to suspended', function () {
    $landlord = makeLandlord();
    $tenant = makeTenant();

    $tenant->suspend($landlord, 'non_payment');

    expect($tenant->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->deactivated_at)->not->toBeNull()
        ->and($tenant->deactivated_by)->toBe($landlord->id)
        ->and($tenant->deactivation_reason)->toBe('non_payment')
        ->and($tenant->isSuspended())->toBeTrue();
});

test('suspend throws when tenant is not active', function () {
    $landlord = makeLandlord();
    $tenant = makeTenant()->factory()->suspended()->create();

    expect(fn () => $tenant->suspend($landlord))
        ->toThrow(RuntimeException::class);
});

// --- markForDeletion() ---

test('markForDeletion transitions suspended tenant', function () {
    $tenant = Tenant::factory()->suspended()->create();

    $tenant->markForDeletion();

    expect($tenant->status)->toBe(TenantStatus::MarkedForDeletion)
        ->and($tenant->marked_for_deletion_at)->not->toBeNull()
        ->and($tenant->isPendingDeletion())->toBeTrue();
});

test('markForDeletion throws when tenant is not suspended', function () {
    $tenant = makeTenant();

    expect(fn () => $tenant->markForDeletion())
        ->toThrow(RuntimeException::class);
});

// --- scheduleForDeletion() ---

test('scheduleForDeletion transitions marked tenant with default 30 day window', function () {
    $tenant = Tenant::factory()->markedForDeletion()->create();

    $tenant->scheduleForDeletion();

    expect($tenant->status)->toBe(TenantStatus::ScheduledForDeletion)
        ->and($tenant->scheduled_for_deletion_at)->not->toBeNull()
        ->and($tenant->deletion_scheduled_for)->not->toBeNull()
        ->and($tenant->deletion_scheduled_for->isFuture())->toBeTrue();
});

test('scheduleForDeletion accepts custom delete date', function () {
    $tenant = Tenant::factory()->markedForDeletion()->create();
    $deleteOn = now()->addDays(60);

    $tenant->scheduleForDeletion($deleteOn);

    expect($tenant->deletion_scheduled_for->toDateString())->toBe($deleteOn->toDateString());
});

test('scheduleForDeletion throws when tenant is suspended (must be marked first)', function () {
    $tenant = Tenant::factory()->suspended()->create();

    expect(fn () => $tenant->scheduleForDeletion())
        ->toThrow(RuntimeException::class);
});

// --- reactivate() ---

test('reactivate clears all lifecycle timestamps', function () {
    $landlord = makeLandlord();
    $tenant = Tenant::factory()->scheduledForDeletion()->create();

    $tenant->reactivate();

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->isActive())->toBeTrue()
        ->and($tenant->deactivated_at)->toBeNull()
        ->and($tenant->deactivated_by)->toBeNull()
        ->and($tenant->deactivation_reason)->toBeNull()
        ->and($tenant->marked_for_deletion_at)->toBeNull()
        ->and($tenant->scheduled_for_deletion_at)->toBeNull()
        ->and($tenant->deletion_scheduled_for)->toBeNull();
});

test('reactivate throws when tenant is already active', function () {
    $tenant = makeTenant();

    expect(fn () => $tenant->reactivate())
        ->toThrow(RuntimeException::class);
});

// --- Scopes ---

test('dueForDeletion scope returns only tenants past deletion date', function () {
    $due = Tenant::factory()->create([
        'status' => TenantStatus::ScheduledForDeletion,
        'scheduled_for_deletion_at' => now()->subDays(5),
        'deletion_scheduled_for' => now()->subDay(),
    ]);

    $notYetDue = Tenant::factory()->create([
        'status' => TenantStatus::ScheduledForDeletion,
        'scheduled_for_deletion_at' => now()->subDays(1),
        'deletion_scheduled_for' => now()->addDays(10),
    ]);

    $results = Tenant::scheduledForDeletion()->dueForDeletion()->pluck('id');

    expect($results)->toContain($due->id)
        ->and($results)->not->toContain($notYetDue->id);
});

// --- TenantDeletionGuard (the logic wired into TenancyServiceProvider's DeletingTenant listener) ---
// The guard is tested directly here because the listener bypasses it in the testing environment.

test('TenantDeletionGuard rejects non-scheduled tenants', function () {
    $tenant = makeTenant(); // Active

    expect(fn () => TenantDeletionGuard::check($tenant))
        ->toThrow(RuntimeException::class, 'status must be ScheduledForDeletion');
});

test('TenantDeletionGuard rejects scheduled tenants whose deletion date is still in the future', function () {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::ScheduledForDeletion,
        'scheduled_for_deletion_at' => now(),
        'deletion_scheduled_for' => now()->addDays(5),
    ]);

    expect(fn () => TenantDeletionGuard::check($tenant))
        ->toThrow(RuntimeException::class, 'deletion_scheduled_for');
});

test('TenantDeletionGuard passes when status is ScheduledForDeletion and date is past', function () {
    $tenant = Tenant::factory()->create([
        'status' => TenantStatus::ScheduledForDeletion,
        'scheduled_for_deletion_at' => now()->subDays(2),
        'deletion_scheduled_for' => now()->subDay(),
    ]);

    expect(fn () => TenantDeletionGuard::check($tenant))->not->toThrow(RuntimeException::class);
});

// --- TenantPolicy ---

test('TenantPolicy delete always returns false', function () {
    $landlord = makeLandlord();
    $tenant = makeTenant();
    $policy = new TenantPolicy;

    expect($policy->delete($landlord, $tenant))->toBeFalse();
});

test('TenantPolicy suspend requires active tenant', function () {
    $landlord = makeLandlord();
    $policy = new TenantPolicy;

    $active = makeTenant();
    $suspended = Tenant::factory()->suspended()->create();

    expect($policy->suspend($landlord, $active))->toBeTrue()
        ->and($policy->suspend($landlord, $suspended))->toBeFalse();
});

test('TenantPolicy reactivate allows any non-active tenant', function () {
    $landlord = makeLandlord();
    $policy = new TenantPolicy;

    $active = makeTenant();
    $suspended = Tenant::factory()->suspended()->create();

    expect($policy->reactivate($landlord, $active))->toBeFalse()
        ->and($policy->reactivate($landlord, $suspended))->toBeTrue();
});

// --- DeleteScheduledTenantsCommand ---

test('hmo:delete-scheduled-tenants does not process future-dated tenants', function () {
    Tenant::factory()->create([
        'status' => TenantStatus::ScheduledForDeletion,
        'scheduled_for_deletion_at' => now(),
        'deletion_scheduled_for' => now()->addDays(30), // not due yet
    ]);

    $this->artisan('hmo:delete-scheduled-tenants')
        ->expectsOutput('No tenants due for deletion.')
        ->assertSuccessful();
});
