<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\VatStatus;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Partner;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerInvoiceService;
use App\Services\TenantOnboardingService;

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

// F-023 — the DB CHECK constraint (NOT is_vat_registered OR vat_number IS NOT NULL) now
// enforces this invariant at the data layer. A non-VAT-registered tenant invoicing an EU B2B
// partner correctly confirms as a B2C scenario (not reverse charge), since scenario determination
// uses the tenant's is_vat_registered flag.
it('non-registered tenant invoicing EU B2B partner confirms as B2C (not reverse charge)', function () {
    $this->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create([
            'vat_status' => VatStatus::Confirmed,
        ]);

        $vatRate = VatRate::factory()->standard()->create();
        $invoice = CustomerInvoice::factory()->draft()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'issued_at' => now()->toDateString(),
        ]);
        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'product_variant_id' => ProductVariant::factory(),
            'vat_rate_id' => $vatRate->id,
            'sales_order_item_id' => null,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        expect($invoice->fresh()->status)->toBe(DocumentStatus::Confirmed)
            ->and($invoice->fresh()->is_reverse_charge)->toBeFalse();
    });
});

// F-028 — 5-day rule fires a warning but does not block confirmation.
it('warns but does not block when issued_at is 10 days after supplied_at', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        // Domestic BG partner → scenario resolves to Domestic, no VIES, no reverse-charge guard.
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $vatRate = VatRate::factory()->standard()->create();

        $invoice = CustomerInvoice::factory()->draft()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'issued_at' => now()->toDateString(),
            'supplied_at' => now()->subDays(10)->toDateString(),
        ]);
        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'product_variant_id' => ProductVariant::factory(),
            'vat_rate_id' => $vatRate->id,
            'sales_order_item_id' => null,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        // The critical assertion is that the late-issuance warning is non-blocking.
        // (The Filament Notification::send() fires from the service; asserting it
        // from a non-Livewire context is unreliable across Filament versions — the
        // load-bearing behaviour is that the invoice still confirms cleanly.)
        expect($invoice->fresh()->status)->toBe(DocumentStatus::Confirmed);
    });
});
