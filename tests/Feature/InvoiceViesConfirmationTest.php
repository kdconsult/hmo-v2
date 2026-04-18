<?php

declare(strict_types=1);

use App\DTOs\ManualOverrideData;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\ReverseChargeOverrideReason;
use App\Enums\VatScenario;
use App\Enums\VatStatus;
use App\Enums\ViesResult;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerInvoiceService;
use App\Services\TenantOnboardingService;
use App\Services\ViesValidationService;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeViesResponse(bool $available, bool $valid, string $requestId = 'REQ-123'): array
{
    return [
        'available' => $available,
        'valid' => $valid,
        'name' => $valid ? 'ACME GmbH' : null,
        'address' => $valid ? 'Berlin, DE' : null,
        'country_code' => 'DE',
        'vat_number' => $valid ? '123456789' : '',
        'request_id' => $available ? $requestId : null,
    ];
}

// ─── Category 1: VIES re-check outcomes ──────────────────────────────────────

test('runViesPreCheck: VIES valid + confirmed partner → partner stays confirmed, returns Valid result', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create([
            'vat_status' => VatStatus::Confirmed,
            'vat_number' => 'DE123456789',
            'is_vat_registered' => true,
            'vies_last_checked_at' => now()->subHour(), // past cooldown
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldReceive('validate')
            ->once()
            ->withArgs(fn ($c, $v, $fresh) => $fresh === true)
            ->andReturn(makeViesResponse(true, true));
        app()->instance(ViesValidationService::class, $mockVies);

        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        $partner->refresh();

        expect($result['needed'])->toBeTrue()
            ->and($result['result'])->toBe(ViesResult::Valid)
            ->and($result['request_id'])->toBe('REQ-123')
            ->and($partner->vat_status)->toBe(VatStatus::Confirmed)
            ->and($partner->vies_verified_at)->not->toBeNull();
    });
});

test('runViesPreCheck: VIES invalid → returns Invalid result with downgrade intent; partner NOT yet mutated (F-024)', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create([
            'vat_status' => VatStatus::Confirmed,
            'vat_number' => 'DE123456789',
            'is_vat_registered' => true,
            'vies_last_checked_at' => now()->subHour(),
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldReceive('validate')->once()->andReturn(makeViesResponse(true, false));
        app()->instance(ViesValidationService::class, $mockVies);

        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        $partner->refresh();

        // Downgrade is staged as intent, NOT applied until confirmWithScenario() tx runs (F-024).
        expect($result['needed'])->toBeTrue()
            ->and($result['result'])->toBe(ViesResult::Invalid)
            ->and($result['partner_mutation']->downgradeToNotRegistered)->toBeTrue()
            ->and($partner->vat_status)->toBe(VatStatus::Confirmed)
            ->and($partner->vat_number)->toBe('DE123456789');
    });
});

test('confirmWithScenario: VIES invalid viesData → partner downgraded inside transaction (F-024)', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create([
            'vat_status' => VatStatus::Confirmed,
            'vat_number' => 'DE123456789',
            'is_vat_registered' => true,
            'vies_last_checked_at' => now()->subHour(),
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldReceive('validate')->once()->andReturn(makeViesResponse(true, false));
        app()->instance(ViesValidationService::class, $mockVies);

        $this->actingAs($user);
        $viesData = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        // Partner still confirmed at this point
        expect($partner->fresh()->vat_status)->toBe(VatStatus::Confirmed);

        // Now confirm — downgrade applied atomically inside tx
        app(CustomerInvoiceService::class)->confirmWithScenario($invoice, viesData: $viesData);

        expect($partner->fresh()->vat_status)->toBe(VatStatus::NotRegistered)
            ->and($partner->fresh()->vat_number)->toBeNull();
    });
});

test('runViesPreCheck: VIES unavailable + confirmed partner → partner unchanged, returns Unavailable', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create([
            'vat_status' => VatStatus::Confirmed,
            'vat_number' => 'DE123456789',
            'is_vat_registered' => true,
            'vies_last_checked_at' => now()->subHour(),
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldReceive('validate')->once()->andReturn(makeViesResponse(false, false));
        app()->instance(ViesValidationService::class, $mockVies);

        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        $partner->refresh();

        expect($result['needed'])->toBeTrue()
            ->and($result['result'])->toBe(ViesResult::Unavailable)
            ->and($result['request_id'])->toBeNull()
            // Partner must NOT be changed when VIES is unavailable
            ->and($partner->vat_status)->toBe(VatStatus::Confirmed)
            ->and($partner->vat_number)->toBe('DE123456789');
    });
});

test('runViesPreCheck: no check for domestic partner', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'BG',
            'vat_status' => VatStatus::Confirmed,
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldNotReceive('validate');
        app()->instance(ViesValidationService::class, $mockVies);

        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        expect($result['needed'])->toBeFalse();
    });
});

test('runViesPreCheck: no check for non-EU partner', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->nonEu('US')->create([
            'vat_status' => VatStatus::Confirmed,
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldNotReceive('validate');
        app()->instance(ViesValidationService::class, $mockVies);

        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        expect($result['needed'])->toBeFalse();
    });
});

test('runViesPreCheck: no check for not_registered partner', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithoutVat('DE')->create([
            'vat_status' => VatStatus::NotRegistered,
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldNotReceive('validate');
        app()->instance(ViesValidationService::class, $mockVies);

        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        expect($result['needed'])->toBeFalse();
    });
});

test('runViesPreCheck: cooldown respected — no VIES call within 1 minute of last check', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create([
            'vat_status' => VatStatus::Confirmed,
            'vat_number' => 'DE123456789',
            'vies_last_checked_at' => now()->subSeconds(30), // within cooldown
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldNotReceive('validate');
        app()->instance(ViesValidationService::class, $mockVies);

        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        expect($result['needed'])->toBeTrue()
            ->and($result['result'])->toBe('cooldown');
    });
});

// ─── Category 2: confirmWithScenario stores audit trail ──────────────────────

test('confirmWithScenario stores vat_scenario on invoice', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        $invoice->refresh();

        expect($invoice->vat_scenario)->toBe(VatScenario::EuB2bReverseCharge);
    });
});

test('confirmWithScenario stores VIES audit columns when viesData provided', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $checkedAt = now()->subSeconds(5);
        $viesData = [
            'needed' => true,
            'result' => ViesResult::Valid,
            'request_id' => 'REQ-AUDIT-456',
            'checked_at' => $checkedAt,
        ];

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice, viesData: $viesData);

        $invoice->refresh();

        expect($invoice->vies_result)->toBe(ViesResult::Valid)
            ->and($invoice->vies_request_id)->toBe('REQ-AUDIT-456')
            ->and($invoice->vies_checked_at->timestamp)->toBe($checkedAt->timestamp);
    });
});

test('vat_scenario is immutable — stays frozen after confirmation', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        $invoice->refresh();
        $frozenScenario = $invoice->vat_scenario;

        // Attempt to confirm again — status guard should throw
        $attemptedConfirmAgain = false;
        try {
            app(CustomerInvoiceService::class)->confirmWithScenario($invoice);
            $attemptedConfirmAgain = true;
        } catch (DomainException) {
            // Expected
        }

        $invoice->refresh();

        expect($attemptedConfirmAgain)->toBeFalse()
            ->and($invoice->vat_scenario)->toBe($frozenScenario);
    });
});

test('confirmWithScenario stores manual override audit trail', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $centralUser = User::first();
        $override = new ManualOverrideData(
            userId: $centralUser->id,
            reason: ReverseChargeOverrideReason::ViesUnavailable,
        );

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice, override: $override);

        $invoice->refresh();

        expect($invoice->reverse_charge_manual_override)->toBeTrue()
            ->and($invoice->reverse_charge_override_user_id)->toBe($centralUser->id)
            ->and($invoice->reverse_charge_override_reason)->toBe(ReverseChargeOverrideReason::ViesUnavailable)
            ->and($invoice->reverse_charge_override_at)->not->toBeNull();
    });
});

// ─── Category 3: Exempt short-circuit ────────────────────────────────────────

test('non-VAT-registered tenant: confirm stores vat_scenario = Exempt', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        // Even with an EU B2B partner, Exempt should win
        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        $invoice->refresh();

        expect($invoice->vat_scenario)->toBe(VatScenario::Exempt)
            ->and($invoice->is_reverse_charge)->toBeFalse();
    });
});

test('non-VAT-registered tenant: runViesPreCheck skips VIES (Exempt always applies)', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create([
            'vat_status' => VatStatus::Confirmed,
            'vat_number' => 'DE123456789',
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $mockVies = mock(ViesValidationService::class);
        $mockVies->shouldNotReceive('validate');
        app()->instance(ViesValidationService::class, $mockVies);

        // Non-VAT-registered tenant short-circuits before VIES — Exempt always applies.
        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        expect($result)->toBe(['needed' => false]);
    });
});

test('non-VAT-registered tenant: Exempt scenario applies zero VAT rate to items', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->euWithVat('DE')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $standardRate->id,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        $invoice->refresh();

        expect($invoice->vat_scenario)->toBe(VatScenario::Exempt)
            ->and($invoice->is_reverse_charge)->toBeFalse()
            ->and((float) $invoice->tax_amount)->toBe(0.0)
            ->and((float) $invoice->total)->toBe(100.0);
    });
});
