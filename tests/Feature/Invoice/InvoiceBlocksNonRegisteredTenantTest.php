<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\VatScenario;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerInvoiceService;
use App\Services\TenantOnboardingService;

/**
 * Build a non-registered-tenant draft invoice with one item at the given rate.
 * Returns the invoice ID.
 */
function makeNonRegisteredDraftInvoiceId(Partner $partner, VatRate $rate): int
{
    CompanySettings::set('company', 'country_code', 'BG');

    $invoice = CustomerInvoice::factory()->create([
        'partner_id' => $partner->id,
        'payment_method' => PaymentMethod::BankTransfer,
        'pricing_mode' => PricingMode::VatExclusive,
    ]);

    CustomerInvoiceItem::factory()->create([
        'customer_invoice_id' => $invoice->id,
        'sales_order_item_id' => null,
        'quantity' => '1.0000',
        'unit_price' => '100.0000',
        'discount_percent' => '0.00',
        'vat_rate_id' => $rate->id,
    ]);

    return $invoice->id;
}

// ─── Confirm flow ────────────────────────────────────────────────────────────

test('confirm sets Exempt scenario for non-registered tenant with domestic partner', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $rate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $invoiceId = makeNonRegisteredDraftInvoiceId($partner, $rate);

        app(CustomerInvoiceService::class)->confirm(CustomerInvoice::find($invoiceId));

        $invoice = CustomerInvoice::find($invoiceId);

        expect($invoice->vat_scenario)->toBe(VatScenario::Exempt)
            ->and($invoice->vat_scenario_sub_code)->toBe('default')
            ->and($invoice->is_reverse_charge)->toBeFalse()
            ->and($invoice->vies_request_id)->toBeNull();
    });
});

test('confirm sets Exempt for non-registered tenant even with EU VAT-registered partner', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $rate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->euWithVat('DE')->create();

        $invoiceId = makeNonRegisteredDraftInvoiceId($partner, $rate);

        app(CustomerInvoiceService::class)->confirm(CustomerInvoice::find($invoiceId));

        $invoice = CustomerInvoice::find($invoiceId);

        expect($invoice->vat_scenario)->toBe(VatScenario::Exempt)
            ->and($invoice->is_reverse_charge)->toBeFalse();
    });
});

test('confirm sets Exempt for non-registered tenant with non-EU partner', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $rate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->nonEu('US')->create();

        $invoiceId = makeNonRegisteredDraftInvoiceId($partner, $rate);

        app(CustomerInvoiceService::class)->confirm(CustomerInvoice::find($invoiceId));

        $invoice = CustomerInvoice::find($invoiceId);

        expect($invoice->vat_scenario)->toBe(VatScenario::Exempt)
            ->and($invoice->is_reverse_charge)->toBeFalse();
    });
});

test('confirm applies 0% VAT rate to all items for non-registered tenant', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $rate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $invoiceId = makeNonRegisteredDraftInvoiceId($partner, $rate);

        app(CustomerInvoiceService::class)->confirm(CustomerInvoice::find($invoiceId));

        $invoice = CustomerInvoice::with('items.vatRate')->find($invoiceId);

        foreach ($invoice->items as $item) {
            expect((float) $item->vatRate->rate)->toBe(0.0);
        }

        expect((float) $invoice->tax_amount)->toBe(0.0)
            ->and((float) $invoice->total)->toBe((float) $invoice->subtotal);
    });
});

test('OSS accumulation is skipped for non-registered tenant on EU partner invoice', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $rate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->euWithoutVat('DE')->create();

        $invoiceId = makeNonRegisteredDraftInvoiceId($partner, $rate);

        $before = EuOssAccumulation::count();

        app(CustomerInvoiceService::class)->confirm(CustomerInvoice::find($invoiceId));

        expect(EuOssAccumulation::count())->toBe($before);
    });
});

// ─── VIES pre-check ──────────────────────────────────────────────────────────

test('runViesPreCheck returns needed=false for non-registered tenant', function () {
    $tenant = Tenant::factory()->create(); // is_vat_registered = false
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        $partner = Partner::factory()->euWithVat('DE')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $result = app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

        expect($result['needed'])->toBeFalse();
    });
});

// ─── UI blocks (form/RM) — Livewire subdomain scaffolding required ────────────

it('invoice form hides pricing-mode, reverse-charge, and DomesticExempt when tenant non-registered')
    ->todo(issue: 'requires Filament panel + subdomain URL scaffolding — ship with manual browser test');

it('items RM restricts vat_rate_id to 0% exempt for non-registered tenant on a draft invoice')
    ->todo(issue: 'requires Filament panel + subdomain URL scaffolding — ship with manual browser test');

it('partner helperText shows exempt message regardless of partner country for non-registered tenant')
    ->todo(issue: 'requires Filament panel + subdomain URL scaffolding — ship with manual browser test');

it('product form restricts vat_rate_id options to 0% exempt for non-registered tenant')
    ->todo(issue: 'requires Filament panel + subdomain URL scaffolding — ship with manual browser test');

it('category form restricts default_vat_rate_id options to 0% exempt for non-registered tenant')
    ->todo(issue: 'requires Filament panel + subdomain URL scaffolding — ship with manual browser test');
