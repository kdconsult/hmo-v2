<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\VatScenario;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PdfTemplateResolver;
use App\Services\TenantOnboardingService;

/**
 * Render a customer-invoice Blade template exactly the way the print action would —
 * resolve template + locale through PdfTemplateResolver and wrap in try/finally.
 */
function renderInvoiceHtml(CustomerInvoice $invoice): string
{
    $invoice->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate']);

    $resolver = app(PdfTemplateResolver::class);
    $view = $resolver->resolve('customer-invoice');
    $locale = $resolver->localeFor('customer-invoice');

    $previous = app()->getLocale();
    app()->setLocale($locale);

    try {
        return view($view, ['invoice' => $invoice])->render();
    } finally {
        app()->setLocale($previous);
    }
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->vatRegistered()->create([
        'country_code' => 'BG',
        'locale' => 'bg',
    ]);
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

afterEach(function () {
    tenancy()->end();
});

it('BG tenant renders ФАКТУРА heading', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::Domestic,
            'vat_scenario_sub_code' => null,
            'is_reverse_charge' => false,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->toContain('ФАКТУРА');
    });
});

it('DE tenant on default template does not render ФАКТУРА', function () {
    $this->tenant->update(['country_code' => 'DE', 'locale' => 'en']);

    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'DE']);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::Domestic,
            'vat_scenario_sub_code' => null,
            'is_reverse_charge' => false,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->not->toContain('ФАКТУРА')
            ->and($html)->toContain('INVOICE');
    });
});

it('renders Обратно начисляване + Art. 138 for reverse-charge goods', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::EuB2bReverseCharge,
            'vat_scenario_sub_code' => 'goods',
            'is_reverse_charge' => true,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->toContain('Обратно начисляване')
            ->and($html)->toContain('Art. 138');
    });
});

it('renders чл. 113, ал. 9 ЗДДС for Exempt invoice', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::Exempt,
            'vat_scenario_sub_code' => 'default',
            'is_reverse_charge' => false,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->toContain('чл. 113, ал. 9 ЗДДС');
    });
});

it('renders чл. 45 ЗДДС for DomesticExempt sub_code art_45', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::DomesticExempt,
            'vat_scenario_sub_code' => 'art_45',
            'is_reverse_charge' => false,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->toContain('чл. 45 ЗДДС');
    });
});

it('renders Art. 146 for Non-EU export goods', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->nonEu('US')->create();
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::NonEuExport,
            'vat_scenario_sub_code' => 'goods',
            'is_reverse_charge' => false,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->toContain('Art. 146');
    });
});

it('renders Art. 44 for Non-EU services', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->nonEu('US')->create();
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::NonEuExport,
            'vat_scenario_sub_code' => 'services',
            'is_reverse_charge' => false,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->toContain('Art. 44')
            ->and($html)->toContain('outside');
    });
});

it('renders VIES consultation number for reverse-charge', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::EuB2bReverseCharge,
            'vat_scenario_sub_code' => 'goods',
            'is_reverse_charge' => true,
            'vies_request_id' => 'WAPIAAAAXZi9QJvU',
            'vies_checked_at' => now(),
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->toContain('WAPIAAAAXZi9QJvU');
    });
});

it('renders date of supply distinct from date of issue', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'vat_scenario' => VatScenario::Domestic,
            'issued_at' => '2026-04-15',
            'supplied_at' => '2026-04-10',
            'due_date' => null,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect($html)->toContain('15.04.2026')
            ->and($html)->toContain('10.04.2026');
    });
});

it('omits supplied_at row when null', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'vat_scenario' => VatScenario::Domestic,
            'issued_at' => '2026-04-15',
            'supplied_at' => null,
            'due_date' => null,
        ]);

        $html = renderInvoiceHtml($invoice);

        expect(substr_count($html, '15.04.2026'))->toBe(1);
    });
});

it('renders per-rate breakdown on mixed rates', function () {
    // The _totals component groups items by vatRate->rate. Factory-building
    // mixed-rate invoices with recalculated totals is non-trivial and unrelated
    // to the render contract; skipping.
})->todo('factory does not ship a mixed-rate invoice helper; _totals component is exercised elsewhere');
