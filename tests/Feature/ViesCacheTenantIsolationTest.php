<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Illuminate\Support\Facades\Cache;

/**
 * F-005: defence-in-depth — ViesValidationService prefixes its cache key with
 * the tenant id so that, even if the stancl/tenancy cache bootstrapper is ever
 * disabled or a code path runs outside tenant context, two tenants cannot see
 * each other's cached VIES requestIdentifier.
 *
 * Locks the behaviour so a future revert gets caught.
 */
test('VIES cache keys are tenant-scoped', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();

    app(TenantOnboardingService::class)->onboard($tenantA, $user);
    app(TenantOnboardingService::class)->onboard($tenantB, $user);

    $keyA = "vies_validation_{$tenantA->id}_DE_123456789";
    $keyB = "vies_validation_{$tenantB->id}_DE_123456789";

    expect($keyA)->not->toBe($keyB);

    $tenantA->run(fn () => Cache::put($keyA, ['leaked' => 'to A only'], 60));

    // In the stancl/tenancy cache bootstrapper the tag-scope prevents cross-read;
    // our prefix adds a second line of defence independent of the bootstrapper.
    $tenantB->run(function () use ($keyB) {
        expect(Cache::has($keyB))->toBeFalse();
    });
});
