<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\VatScenario;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PdfTemplateResolver;
use App\Services\TenantOnboardingService;

function renderCreditNoteHtml(CustomerCreditNote $note): string
{
    $note->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate', 'customerInvoice']);

    $resolver = app(PdfTemplateResolver::class);
    $view = $resolver->resolve('customer-credit-note');
    $locale = $resolver->localeFor('customer-credit-note');

    $previous = app()->getLocale();
    app()->setLocale($locale);

    try {
        return view($view, ['note' => $note])->render();
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

it('BG tenant renders КРЕДИТНО ИЗВЕСТИЕ heading', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::Domestic,
            'vat_scenario_sub_code' => null,
            'is_reverse_charge' => false,
        ]);
        $note = CustomerCreditNote::factory()->confirmed()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $partner->id,
        ]);

        $html = renderCreditNoteHtml($note);

        expect($html)->toContain('КРЕДИТНО ИЗВЕСТИЕ');
    });
});

it('credit note inherits parent invoice legal reference for reverse-charge services', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->euWithVat('DE')->create();
        // Parent invoice owns vat_scenario + is_reverse_charge + vies data (template reads them from $note->customerInvoice).
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::EuB2bReverseCharge,
            'vat_scenario_sub_code' => 'services',
            'is_reverse_charge' => true,
            'vies_request_id' => 'WAPIAAAAXREV1234',
            'vies_checked_at' => now(),
        ]);
        // Note owns its own sub_code (the template passes $note->vat_scenario_sub_code to _vat-treatment).
        $note = CustomerCreditNote::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'issued_at' => now()->toDateString(),
            'vat_scenario_sub_code' => 'services',
        ]);

        $html = renderCreditNoteHtml($note);

        // Seeder: 'Art. 44 + 196 Directive 2006/112/EC' for eu_b2b_reverse_charge + services
        expect($html)->toContain('Art. 44 + 196')
            ->and($html)->toContain('Обратно начисляване')
            // Parent invoice number renders in the header meta block.
            ->and($html)->toContain($invoice->invoice_number);
    });
});
