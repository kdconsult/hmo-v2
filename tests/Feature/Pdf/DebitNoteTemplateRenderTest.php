<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\VatScenario;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PdfTemplateResolver;
use App\Services\TenantOnboardingService;

function renderDebitNoteHtml(CustomerDebitNote $note): string
{
    $note->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate', 'customerInvoice']);

    $resolver = app(PdfTemplateResolver::class);
    $view = $resolver->resolve('customer-debit-note');
    $locale = $resolver->localeFor('customer-debit-note');

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

it('BG tenant renders ДЕБИТНО ИЗВЕСТИЕ heading', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::Domestic,
            'vat_scenario_sub_code' => null,
            'is_reverse_charge' => false,
        ]);
        $note = CustomerDebitNote::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'issued_at' => now()->toDateString(),
        ]);

        $html = renderDebitNoteHtml($note);

        expect($html)->toContain('ДЕБИТНО ИЗВЕСТИЕ');
    });
});

it('debit note inherits parent invoice legal reference for reverse-charge goods', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::EuB2bReverseCharge,
            'vat_scenario_sub_code' => 'goods',
            'is_reverse_charge' => true,
            'vies_request_id' => 'WAPIAAAAXDEB5678',
            'vies_checked_at' => now(),
        ]);
        $note = CustomerDebitNote::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'issued_at' => now()->toDateString(),
            'vat_scenario' => VatScenario::EuB2bReverseCharge,
            'vat_scenario_sub_code' => 'goods',
            'is_reverse_charge' => true,
        ]);

        $html = renderDebitNoteHtml($note);

        // Seeder: 'Art. 138 Directive 2006/112/EC' for eu_b2b_reverse_charge + goods
        expect($html)->toContain('Art. 138')
            ->and($html)->toContain('Обратно начисляване')
            ->and($html)->toContain($invoice->invoice_number);
    });
});
