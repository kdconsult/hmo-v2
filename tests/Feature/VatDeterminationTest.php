<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\VatScenario;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Services\CustomerInvoiceService;
use App\Services\TenantOnboardingService;

// ─── Category A: VatScenario::determine() ────────────────────────────────────

test('VatScenario::determine returns Domestic when partner country equals tenant country', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        expect(VatScenario::determine($partner, 'BG'))->toBe(VatScenario::Domestic);
    });
});

test('VatScenario::determine returns EuB2bReverseCharge for EU partner with valid VAT', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->euWithVat('DE')->create();

        expect(VatScenario::determine($partner, 'BG'))->toBe(VatScenario::EuB2bReverseCharge);
    });
});

test('VatScenario::determine returns EuB2cUnderThreshold for EU B2C when OSS threshold not exceeded', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        // Accumulate below threshold
        EuOssAccumulation::accumulate('DE', (int) now()->year, 3000.0);

        $partner = Partner::factory()->euWithoutVat('DE')->create();

        expect(VatScenario::determine($partner, 'BG'))->toBe(VatScenario::EuB2cUnderThreshold);
    });
});

test('VatScenario::determine returns EuB2cOverThreshold for EU B2C when OSS threshold exceeded', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        EuOssAccumulation::accumulate('DE', (int) now()->year, 11000.0);

        $partner = Partner::factory()->euWithoutVat('DE')->create();

        expect(VatScenario::determine($partner, 'BG'))->toBe(VatScenario::EuB2cOverThreshold);
    });
});

test('VatScenario::determine returns NonEuExport for non-EU partner', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->nonEu('US')->create();

        expect(VatScenario::determine($partner, 'BG'))->toBe(VatScenario::NonEuExport);
    });
});

test('VatScenario::determine returns NonEuExport when partner has empty country_code', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => null]);

        expect(VatScenario::determine($partner, 'BG'))->toBe(VatScenario::NonEuExport);
    });
});

// ─── Category B: confirm() integration ───────────────────────────────────────

test('confirm keeps original VAT rates for domestic sale', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $item = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_rate_id' => $standardRate->id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $item->refresh();
        $invoice->refresh();

        expect($item->vat_rate_id)->toBe($standardRate->id)
            ->and($invoice->is_reverse_charge)->toBeFalse()
            ->and($invoice->status)->toBe(DocumentStatus::Confirmed);
    });
});

test('confirm sets reverse charge and zero VAT rate for EU B2B partner', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->euWithVat('DE')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $item = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $standardRate->id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $item->refresh();
        $invoice->refresh();

        $zeroRate = VatRate::where('country_code', 'BG')->where('type', 'zero')->first();

        expect($invoice->is_reverse_charge)->toBeTrue()
            ->and($item->vat_rate_id)->toBe($zeroRate->id)
            ->and((float) $item->vat_amount)->toBe(0.0)
            ->and((float) $item->line_total)->toBe(200.0)
            ->and((float) $invoice->tax_amount)->toBe(0.0)
            ->and((float) $invoice->total)->toBe(200.0);
    });
});

test('confirm keeps original VAT rates for EU B2C under OSS threshold', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        EuOssAccumulation::accumulate('DE', (int) now()->year, 3000.0);

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->euWithoutVat('DE')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $item = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_rate_id' => $standardRate->id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $item->refresh();
        $invoice->refresh();

        expect($item->vat_rate_id)->toBe($standardRate->id)
            ->and($invoice->is_reverse_charge)->toBeFalse();
    });
});

test('confirm applies destination country VAT rate for EU B2C over OSS threshold', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        // Germany standard rate = 19%, seeded by EuCountryVatRatesSeeder via onboard()
        EuOssAccumulation::accumulate('DE', (int) now()->year, 11000.0);

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->euWithoutVat('DE')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $standardRate->id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $invoice->refresh();
        $deRate = VatRate::where('country_code', 'DE')->where('type', 'standard')->first();

        expect($deRate)->not->toBeNull()
            ->and((float) $deRate->rate)->toBe(19.0)
            ->and($invoice->is_reverse_charge)->toBeFalse()
            ->and((float) $invoice->tax_amount)->toBe(19.0)
            ->and((float) $invoice->total)->toBe(119.0);
    });
});

test('confirm applies zero VAT rate for non-EU export', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->nonEu('US')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $standardRate->id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $invoice->refresh();

        expect($invoice->is_reverse_charge)->toBeFalse()
            ->and((float) $invoice->tax_amount)->toBe(0.0)
            ->and((float) $invoice->total)->toBe(100.0);
    });
});

test('confirm throws DomainException when company country code is not configured', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        // Do NOT set CompanySettings country_code
        $invoice = CustomerInvoice::factory()->create([
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        expect(fn () => app(CustomerInvoiceService::class)->confirm($invoice))
            ->toThrow(DomainException::class, 'Company country code is not configured');
    });
});

test('confirm throws DomainException when called on a non-Draft invoice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        expect(fn () => app(CustomerInvoiceService::class)->confirm($invoice))
            ->toThrow(DomainException::class, 'Only draft invoices can be confirmed');
    });
});

test('confirm applies zero VAT to all items consistently for EU B2B multi-item invoice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->euWithVat('DE')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '2.0000',
            'unit_price' => '50.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $standardRate->id,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '3.0000',
            'unit_price' => '40.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $standardRate->id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $invoice->refresh();
        $zeroRate = VatRate::where('country_code', 'BG')->where('type', 'zero')->first();

        // 2×50 + 3×40 = 100 + 120 = 220, VAT 0%
        expect($invoice->is_reverse_charge)->toBeTrue()
            ->and((float) $invoice->tax_amount)->toBe(0.0)
            ->and((float) $invoice->total)->toBe(220.0);

        $invoice->items->each(function ($item) use ($zeroRate) {
            expect($item->vat_rate_id)->toBe($zeroRate->id)
                ->and((float) $item->vat_amount)->toBe(0.0);
        });
    });
});

test('confirm creates zero-rate VatRate via firstOrCreate when none exists', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        // Ensure no zero-rate for BG exists before confirm
        VatRate::where('country_code', 'BG')->where('type', 'zero')->delete();

        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        expect(VatRate::where('country_code', 'BG')->where('type', 'zero')->exists())->toBeTrue();
    });
});

test('confirm creates destination-country VatRate from EuCountryVatRate reference when none exists', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        EuOssAccumulation::accumulate('DE', (int) now()->year, 11000.0);

        // Ensure no DE standard rate VatRate exists
        VatRate::where('country_code', 'DE')->where('type', 'standard')->delete();

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);
        $partner = Partner::factory()->euWithoutVat('DE')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'vat_rate_id' => $standardRate->id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $deRate = VatRate::where('country_code', 'DE')->where('type', 'standard')->first();

        expect($deRate)->not->toBeNull()
            ->and((float) $deRate->rate)->toBe(19.0); // Germany standard rate from EuCountryVatRatesSeeder
    });
});

// ─── Category C: Edge cases ───────────────────────────────────────────────────

test('confirm routes EU partner with no VAT number through B2C path, not reverse charge', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithoutVat('DE')->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $invoice->refresh();

        expect($invoice->is_reverse_charge)->toBeFalse()
            ->and($invoice->status)->toBe(DocumentStatus::Confirmed);
    });
});

test('is_reverse_charge persists after cancel and is not reset by the cancel operation', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create();
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);
        $invoice->refresh();

        expect($invoice->is_reverse_charge)->toBeTrue();

        // Manually set Confirmed so cancel() doesn't check status (cancel has no status guard)
        app(CustomerInvoiceService::class)->cancel($invoice);
        $invoice->refresh();

        expect($invoice->status)->toBe(DocumentStatus::Cancelled)
            ->and($invoice->is_reverse_charge)->toBeTrue(); // preserved — not reset by cancel
    });
});

test('confirm applies VAT determination inside transaction that also updates SO qty_invoiced', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->euWithVat('DE')->create();

        $warehouse = Warehouse::factory()->create();
        $so = SalesOrder::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $soItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $so->id,
            'quantity' => '2.0000',
            'qty_invoiced' => '0.0000',
        ]);

        $standardRate = VatRate::factory()->standard()->create(['rate' => '20.00', 'country_code' => 'BG']);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'sales_order_id' => $so->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'sales_order_item_id' => $soItem->id,
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $standardRate->id,
            'product_variant_id' => $soItem->product_variant_id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $soItem->refresh();
        $invoice->refresh();

        // Both VAT determination and SO update happened in the same transaction
        expect($invoice->is_reverse_charge)->toBeTrue()
            ->and((float) $invoice->tax_amount)->toBe(0.0)
            ->and((float) $soItem->qty_invoiced)->toBe(2.0);
    });
});

test('OSS threshold boundary: invoice just above EUR 10000 triggers OSS rate', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        EuOssAccumulation::accumulate('DE', (int) now()->year, 10001.0);

        $partner = Partner::factory()->euWithoutVat('DE')->create();

        expect(VatScenario::determine($partner, 'BG'))->toBe(VatScenario::EuB2cOverThreshold);
    });
});
