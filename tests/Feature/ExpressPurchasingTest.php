<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\GoodsReceivedNoteStatus;
use App\Enums\PricingMode;
use App\Enums\SeriesType;
use App\Models\NumberSeries;
use App\Models\Partner;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Services\SupplierInvoiceService;
use App\Services\TenantOnboardingService;

function createGrnSeries(): NumberSeries
{
    return NumberSeries::create([
        'series_type' => SeriesType::GoodsReceivedNote->value,
        'name' => 'GRN Series',
        'prefix' => 'GRN',
        'separator' => '-',
        'include_year' => false,
        'padding' => 5,
        'next_number' => 1,
        'reset_yearly' => false,
        'is_default' => true,
        'is_active' => true,
    ]);
}

test('confirmAndReceive confirms invoice and creates confirmed GRN with items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        createGrnSeries();

        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $partner = Partner::factory()->supplier()->create();
        $warehouse = Warehouse::factory()->create(['is_default' => true, 'is_active' => true]);

        $invoice = SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'amount_paid' => '0.00',
        ]);

        $variant1 = ProductVariant::factory()->create();
        $variant2 = ProductVariant::factory()->create();

        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_variant_id' => $variant1->id,
            'quantity' => '5.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_variant_id' => $variant2->id,
            'quantity' => '3.0000',
            'unit_price' => '50.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        $grn = app(SupplierInvoiceService::class)->confirmAndReceive($invoice, $warehouse);

        // SI is confirmed
        expect($invoice->fresh()->status)->toBe(DocumentStatus::Confirmed);

        // GRN created, confirmed, linked to SI
        expect($grn->status)->toBe(GoodsReceivedNoteStatus::Confirmed)
            ->and($grn->supplier_invoice_id)->toBe($invoice->id)
            ->and($grn->partner_id)->toBe($partner->id)
            ->and($grn->warehouse_id)->toBe($warehouse->id);

        // GRN has correct items
        expect($grn->items()->count())->toBe(2);

        // Stock increased
        expect(StockItem::where('product_variant_id', $variant1->id)->value('quantity'))->toBe('5.0000');
        expect(StockItem::where('product_variant_id', $variant2->id)->value('quantity'))->toBe('3.0000');
    });
});

test('confirmAndReceive skips free-text SI lines', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        createGrnSeries();

        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $partner = Partner::factory()->supplier()->create();
        $warehouse = Warehouse::factory()->create(['is_default' => true, 'is_active' => true]);

        $invoice = SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'amount_paid' => '0.00',
        ]);

        $variant = ProductVariant::factory()->create();

        // Stockable line
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_variant_id' => $variant->id,
            'quantity' => '2.0000',
            'unit_price' => '10.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Free-text line (no variant)
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_variant_id' => null,
            'description' => 'Shipping fee',
            'quantity' => '1.0000',
            'unit_price' => '15.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        $grn = app(SupplierInvoiceService::class)->confirmAndReceive($invoice, $warehouse);

        // GRN has only the stockable item (shipping fee skipped)
        expect($grn->items()->count())->toBe(1)
            ->and($grn->items->first()->product_variant_id)->toBe($variant->id);
    });
});

test('confirmAndReceive links GRN to PO and updates PO received quantities', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        createGrnSeries();

        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $partner = Partner::factory()->supplier()->create();
        $warehouse = Warehouse::factory()->create(['is_default' => true, 'is_active' => true]);

        $po = PurchaseOrder::factory()->create(['partner_id' => $partner->id]);
        $variant = ProductVariant::factory()->create();
        $poItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'quantity_received' => '0.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $invoice = SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'purchase_order_id' => $po->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'amount_paid' => '0.00',
        ]);

        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_order_item_id' => $poItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'unit_price' => '50.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        $grn = app(SupplierInvoiceService::class)->confirmAndReceive($invoice, $warehouse);

        // GRN linked to both PO and SI
        expect($grn->purchase_order_id)->toBe($po->id)
            ->and($grn->supplier_invoice_id)->toBe($invoice->id);

        // PO qty_received updated
        expect($poItem->fresh()->quantity_received)->toBe('10.0000');
    });
});

test('confirmAndReceive throws on non-draft invoice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::factory()->create(['is_active' => true]);
        $confirmedInvoice = SupplierInvoice::factory()->confirmed()->create();

        expect(fn () => app(SupplierInvoiceService::class)->confirmAndReceive($confirmedInvoice, $warehouse))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('confirmAndReceive throws when all SI lines are free-text', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $warehouse = Warehouse::factory()->create(['is_active' => true]);
        $invoice = SupplierInvoice::factory()->create(['amount_paid' => '0.00']);

        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_variant_id' => null,
            'description' => 'Handling fee',
            'quantity' => '1.0000',
            'unit_price' => '10.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        expect(fn () => app(SupplierInvoiceService::class)->confirmAndReceive($invoice, $warehouse))
            ->toThrow(InvalidArgumentException::class, 'no stockable items');
    });
});

test('confirmAndReceive throws when no GRN number series configured', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $warehouse = Warehouse::factory()->create(['is_active' => true]);
        $invoice = SupplierInvoice::factory()->create(['amount_paid' => '0.00']);

        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'quantity' => '1.0000',
            'unit_price' => '10.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        // No GRN NumberSeries seeded — should throw
        expect(fn () => app(SupplierInvoiceService::class)->confirmAndReceive($invoice, $warehouse))
            ->toThrow(InvalidArgumentException::class, 'No active number series');
    });
});
