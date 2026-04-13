<?php

declare(strict_types=1);

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyRateService;
use App\Services\TenantOnboardingService;
use Carbon\Carbon;

test('returns 1.000000 when currency equals base currency', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(CurrencyRateService::class);

        expect($service->getRate('EUR', today()))->toBe('1.000000');
    });
});

test('returns exact rate for matching date', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(CurrencyRateService::class);
        $usd = Currency::where('code', 'USD')->first();

        ExchangeRate::create([
            'currency_id' => $usd->id,
            'base_currency_code' => 'EUR',
            'rate' => '1.082000',
            'source' => 'manual',
            'date' => '2026-04-10',
        ]);

        expect($service->getRate('USD', Carbon::parse('2026-04-10')))->toBe('1.082000');
    });
});

test('falls back to most recent prior rate when no exact date match', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(CurrencyRateService::class);
        $usd = Currency::where('code', 'USD')->first();

        ExchangeRate::create([
            'currency_id' => $usd->id,
            'base_currency_code' => 'EUR',
            'rate' => '1.079000',
            'source' => 'manual',
            'date' => '2026-04-07', // Monday
        ]);

        // Lookup on Wednesday — should return Monday's rate
        expect($service->getRate('USD', Carbon::parse('2026-04-09')))->toBe('1.079000');
    });
});

test('returns null when no rate exists for currency', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(CurrencyRateService::class);

        // GBP is seeded but has no exchange rates
        expect($service->getRate('GBP', today()))->toBeNull();
    });
});

test('returns null for unknown currency code', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(CurrencyRateService::class);

        expect($service->getRate('XYZ', today()))->toBeNull();
    });
});

test('does not use future rates as fallback', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(CurrencyRateService::class);
        $usd = Currency::where('code', 'USD')->first();

        ExchangeRate::create([
            'currency_id' => $usd->id,
            'base_currency_code' => 'EUR',
            'rate' => '1.090000',
            'source' => 'manual',
            'date' => today()->addDay()->toDateString(),
        ]);

        expect($service->getRate('USD', today()))->toBeNull();
    });
});

test('getBaseCurrencyCode returns the default currency', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(CurrencyRateService::class);

        expect($service->getBaseCurrencyCode())->toBe('EUR');
    });
});
