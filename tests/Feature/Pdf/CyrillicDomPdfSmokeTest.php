<?php

declare(strict_types=1);

use App\Enums\VatScenario;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\PdfTemplateResolver;
use App\Services\TenantOnboardingService;
use Barryvdh\DomPDF\Facade\Pdf;

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

it('DomPDF renders BG invoice binary without crashing', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $vatRate = VatRate::factory()->standard()->create();
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'vat_scenario' => VatScenario::Domestic,
            'vat_scenario_sub_code' => null,
            'is_reverse_charge' => false,
        ]);
        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'vat_rate_id' => $vatRate->id,
            'sales_order_item_id' => null,
        ]);

        $invoice->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate']);

        $resolver = app(PdfTemplateResolver::class);
        $view = $resolver->resolve('customer-invoice');
        $locale = $resolver->localeFor('customer-invoice');

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $pdf = Pdf::loadView($view, ['invoice' => $invoice]);
            $binary = $pdf->output();
        } finally {
            app()->setLocale($previous);
        }

        expect(strlen($binary))->toBeGreaterThan(1000)
            ->and(str_starts_with($binary, '%PDF-'))->toBeTrue();
    });
});
