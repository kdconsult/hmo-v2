<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\Partner;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\TenantOnboardingService;
use Illuminate\Database\QueryException;

test('supplier invoice can be created', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->supplier()->create();

        $invoice = SupplierInvoice::create([
            'supplier_invoice_number' => 'INV-SUPPLIER-001',
            'internal_number' => 'SI-001',
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'amount_paid' => '0.00',
            'amount_due' => '0.00',
            'issued_at' => now()->toDateString(),
        ]);

        expect($invoice->supplier_invoice_number)->toBe('INV-SUPPLIER-001')
            ->and($invoice->status)->toBe(DocumentStatus::Draft)
            ->and($invoice->isEditable())->toBeTrue();
    });
});

test('internal number is unique per tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        SupplierInvoice::factory()->create(['internal_number' => 'SI-DUPE']);

        expect(fn () => SupplierInvoice::factory()->create(['internal_number' => 'SI-DUPE']))
            ->toThrow(QueryException::class);
    });
});

test('supplier and invoice number combination is unique', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->supplier()->create();
        SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'supplier_invoice_number' => 'SUP-001',
        ]);

        expect(fn () => SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'supplier_invoice_number' => 'SUP-001',
        ]))->toThrow(QueryException::class);
    });
});

test('supplier invoice status transitions to confirmed', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoice = SupplierInvoice::factory()->draft()->create();

        $invoice->status = DocumentStatus::Confirmed;
        $invoice->save();

        expect($invoice->fresh()->status)->toBe(DocumentStatus::Confirmed)
            ->and($invoice->fresh()->isEditable())->toBeFalse();
    });
});

test('supplier invoice recalculates totals from items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $invoice = SupplierInvoice::factory()->create([
            'pricing_mode' => PricingMode::VatExclusive,
            'discount_amount' => '0.00',
            'amount_paid' => '0.00',
        ]);

        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '5.0000',
            'unit_price' => '50.0000',
            'discount_percent' => '0.00',
            'discount_amount' => '0.00',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '50.00',
            'line_total' => '250.00',
            'line_total_with_vat' => '300.00',
        ]);

        $invoice->load('items');
        $invoice->recalculateTotals();
        $invoice->refresh();

        expect((float) $invoice->subtotal)->toBe(250.0)
            ->and((float) $invoice->tax_amount)->toBe(50.0)
            ->and((float) $invoice->total)->toBe(300.0)
            ->and((float) $invoice->amount_due)->toBe(300.0);
    });
});

test('isOverdue returns true when due date has passed and amount is due', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoice = SupplierInvoice::factory()->confirmed()->create([
            'due_date' => now()->subDays(5)->toDateString(),
            'amount_due' => '100.00',
        ]);

        expect($invoice->isOverdue())->toBeTrue();
    });
});

test('supplier invoice can be soft deleted and restored', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoice = SupplierInvoice::factory()->draft()->create(['internal_number' => 'SI-DEL']);
        $invoice->delete();

        expect(SupplierInvoice::where('internal_number', 'SI-DEL')->count())->toBe(0);

        $invoice->restore();
        expect(SupplierInvoice::where('internal_number', 'SI-DEL')->count())->toBe(1);
    });
});
