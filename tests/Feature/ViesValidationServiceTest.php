<?php

declare(strict_types=1);

use App\Models\CompanySettings;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use App\Services\ViesValidationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Returns a fake VIES checkVatApprox response object.
 */
function fakeSoapResponse(bool $valid, string $requestId = 'REQ-TEST-123', ?string $traderName = 'ACME GmbH', ?string $traderAddress = 'Berlin 10115 DE'): object
{
    return (object) [
        'valid' => $valid,
        'traderName' => $traderName ?? '---',
        'traderAddress' => $traderAddress ?? '---',
        'requestIdentifier' => $valid ? $requestId : null,
    ];
}

/**
 * Creates a ViesValidationService subclass that injects a controllable fake SoapClient,
 * capturing the parameters passed to checkVatApprox so tests can assert them.
 */
function makeServiceWithFakeSoap(object $fakeSoapClient): ViesValidationService
{
    return new class($fakeSoapClient) extends ViesValidationService
    {
        private array $capturedParams = [];

        public function __construct(private readonly object $fakeClient) {}

        protected function makeSoapClient(): SoapClient
        {
            // PHP does not enforce return type hints at runtime — the fake client
            // is a Mockery proxy that responds to checkVatApprox() correctly.
            return $this->fakeClient; // @phpstan-ignore-line
        }
    };
}

// ─── SOAP parameter building ──────────────────────────────────────────────────

test('callVies: strips country prefix from tenant VAT number for requesterVatNumber', function () {
    // This is the bug that was shipped: "BG987654321" was sent as requesterVatNumber
    // instead of "987654321", causing INVALID_REQUESTER_INFO from the VIES service.
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => 'BG987654321']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $capturedParams = null;

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;

                return true;
            })
            ->andReturn(fakeSoapResponse(true));

        makeServiceWithFakeSoap($fakeSoap)->validate('RO', '123456789');

        expect($capturedParams['requesterCountryCode'])->toBe('BG')
            ->and($capturedParams['requesterVatNumber'])->toBe('987654321') // no "BG" prefix
            ->and($capturedParams['countryCode'])->toBe('RO')
            ->and($capturedParams['vatNumber'])->toBe('123456789');
    });
});

test('callVies: requesterVatNumber kept as-is when stored without country prefix', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => '987654321']); // no prefix
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $capturedParams = null;

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;

                return true;
            })
            ->andReturn(fakeSoapResponse(true));

        makeServiceWithFakeSoap($fakeSoap)->validate('RO', '123456789');

        expect($capturedParams['requesterVatNumber'])->toBe('987654321');
    });
});

test('callVies: passes empty requester fields when tenant has no VAT number', function () {
    $tenant = Tenant::factory()->create(['vat_number' => null, 'is_vat_registered' => false]);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $capturedParams = null;

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->once()
            ->withArgs(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;

                return true;
            })
            ->andReturn(fakeSoapResponse(true));

        makeServiceWithFakeSoap($fakeSoap)->validate('RO', '123456789');

        // Empty strings are passed — checkVatApprox still validates but won't return requestIdentifier
        expect($capturedParams['requesterCountryCode'])->toBe('')
            ->and($capturedParams['requesterVatNumber'])->toBe('');
    });
});

// ─── Response parsing ─────────────────────────────────────────────────────────

test('validate: valid VIES response is parsed correctly', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => 'BG987654321']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->andReturn(fakeSoapResponse(true, 'REQ-XYZ', 'Test GmbH', 'Berlin DE'));

        $result = makeServiceWithFakeSoap($fakeSoap)->validate('DE', '123456789');

        expect($result['available'])->toBeTrue()
            ->and($result['valid'])->toBeTrue()
            ->and($result['name'])->toBe('Test GmbH')
            ->and($result['address'])->toBe('Berlin DE')
            ->and($result['request_id'])->toBe('REQ-XYZ')
            ->and($result['country_code'])->toBe('DE')
            ->and($result['vat_number'])->toBe('123456789');
    });
});

test('validate: VIES "---" trader name and address are normalised to null', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => 'BG987654321']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->andReturn(fakeSoapResponse(true, 'REQ-XYZ', null, null)); // fakeSoapResponse sets '---' for null

        $result = makeServiceWithFakeSoap($fakeSoap)->validate('DE', '123456789');

        expect($result['name'])->toBeNull()
            ->and($result['address'])->toBeNull();
    });
});

test('validate: invalid VIES response returns valid = false with no request_id', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => 'BG987654321']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->andReturn(fakeSoapResponse(false));

        $result = makeServiceWithFakeSoap($fakeSoap)->validate('DE', 'INVALID');

        expect($result['available'])->toBeTrue()
            ->and($result['valid'])->toBeFalse()
            ->and($result['request_id'])->toBeNull();
    });
});

// ─── SOAP fault / unavailability ──────────────────────────────────────────────

test('validate: SOAP exception returns available = false and logs a warning', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => 'BG987654321']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->andThrow(new SoapFault('Server', 'MS_MAX_CONCURRENT_REQ'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'VIES validation failed'));

        $result = makeServiceWithFakeSoap($fakeSoap)->validate('DE', '123456789');

        expect($result['available'])->toBeFalse()
            ->and($result['valid'])->toBeFalse()
            ->and($result['request_id'])->toBeNull();
    });
});

test('validate: unavailable result is NOT cached so the next call retries', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => 'BG987654321']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->once()
            ->ordered()
            ->andThrow(new SoapFault('Server', 'MS_MAX_CONCURRENT_REQ'));
        $fakeSoap->shouldReceive('checkVatApprox')
            ->once()
            ->ordered()
            ->andReturn(fakeSoapResponse(true, 'REQ-RETRY'));

        Log::shouldReceive('warning')->once();

        $service = makeServiceWithFakeSoap($fakeSoap);

        $first = $service->validate('DE', '123456789');
        $second = $service->validate('DE', '123456789'); // must hit SOAP again, not from cache

        expect($first['available'])->toBeFalse()
            ->and($second['available'])->toBeTrue()
            ->and($second['request_id'])->toBe('REQ-RETRY');
    });
});

// ─── Caching ──────────────────────────────────────────────────────────────────

test('validate: valid result is cached — second call does not hit SOAP', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => 'BG987654321']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->once() // only called once — second call should hit cache
            ->andReturn(fakeSoapResponse(true, 'REQ-CACHED'));

        $service = makeServiceWithFakeSoap($fakeSoap);

        $first = $service->validate('DE', '123456789');
        $second = $service->validate('DE', '123456789');

        expect($first['request_id'])->toBe('REQ-CACHED')
            ->and($second['request_id'])->toBe('REQ-CACHED');
    });
});

test('validate: fresh = true bypasses cache and calls SOAP again', function () {
    $tenant = Tenant::factory()->vatRegistered()->create(['vat_number' => 'BG987654321']);
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $fakeSoap = Mockery::mock('SoapClient');
        $fakeSoap->shouldReceive('checkVatApprox')
            ->twice() // once for first call, once for fresh=true call
            ->andReturn(
                fakeSoapResponse(true, 'REQ-FIRST'),
                fakeSoapResponse(true, 'REQ-FRESH'),
            );

        $service = makeServiceWithFakeSoap($fakeSoap);

        $first = $service->validate('DE', '123456789');
        $fresh = $service->validate('DE', '123456789', fresh: true);

        expect($first['request_id'])->toBe('REQ-FIRST')
            ->and($fresh['request_id'])->toBe('REQ-FRESH');
    });
});
