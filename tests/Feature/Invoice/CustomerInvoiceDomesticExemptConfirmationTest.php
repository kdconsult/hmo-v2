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
 * Build a VAT-registered BG tenant with a draft domestic (BG→BG) customer invoice and
 * `$itemCount` items at the given standard rate. Returns the invoice ID so tests can
 * re-fetch inside a fresh scope. Assumes the caller has already initialized tenancy.
 */
function makeDomesticDraftInvoiceId(int $itemCount = 3, string $standardRate = '20.00'): int
{
    CompanySettings::set('company', 'country_code', 'BG');

    $standard = VatRate::factory()->standard()->create([
        'rate' => $standardRate,
        'country_code' => 'BG',
    ]);

    $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

    $invoice = CustomerInvoice::factory()->create([
        'partner_id' => $partner->id,
        'payment_method' => PaymentMethod::BankTransfer,
        'pricing_mode' => PricingMode::VatExclusive,
    ]);

    for ($i = 0; $i < $itemCount; $i++) {
        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'sales_order_item_id' => null,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $standard->id,
        ]);
    }

    return $invoice->id;
}

test('confirms domestic invoice as DomesticExempt when isDomesticExempt=true', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoiceId = makeDomesticDraftInvoiceId(itemCount: 1);

        $invoice = CustomerInvoice::findOrFail($invoiceId);

        app(CustomerInvoiceService::class)->confirmWithScenario(
            $invoice,
            isDomesticExempt: true,
            subCode: 'art_45',
        );

        $invoice->refresh();

        expect($invoice->vat_scenario)->toBe(VatScenario::DomesticExempt)
            ->and($invoice->vat_scenario_sub_code)->toBe('art_45')
            ->and($invoice->is_reverse_charge)->toBeFalse();
    });
});

test('applies 0% rate to items on DomesticExempt confirm', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoiceId = makeDomesticDraftInvoiceId(itemCount: 3);

        $invoice = CustomerInvoice::findOrFail($invoiceId);

        app(CustomerInvoiceService::class)->confirmWithScenario(
            $invoice,
            isDomesticExempt: true,
            subCode: 'art_39',
        );

        $invoice->refresh()->load('items.vatRate');

        expect($invoice->items)->toHaveCount(3);

        $invoice->items->each(function (CustomerInvoiceItem $item): void {
            expect((float) $item->vatRate->rate)->toBe(0.0)
                ->and((float) $item->vat_amount)->toBe(0.0);
        });
    });
});

test('skips OSS accumulation for DomesticExempt', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoiceId = makeDomesticDraftInvoiceId(itemCount: 1);

        $before = EuOssAccumulation::count();

        $invoice = CustomerInvoice::findOrFail($invoiceId);

        app(CustomerInvoiceService::class)->confirmWithScenario(
            $invoice,
            isDomesticExempt: true,
            subCode: 'art_39',
        );

        expect(EuOssAccumulation::count())->toBe($before);
    });
});

test('throws DomainException when isDomesticExempt=true and subCode is null', function () {
    $tenant = Tenant::factory()->vatRegistered()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoiceId = makeDomesticDraftInvoiceId(itemCount: 1);

        $invoice = CustomerInvoice::findOrFail($invoiceId);

        expect(fn () => app(CustomerInvoiceService::class)->confirmWithScenario(
            $invoice,
            isDomesticExempt: true,
            subCode: null,
        ))->toThrow(DomainException::class, 'requires a sub_code');
    });
});
