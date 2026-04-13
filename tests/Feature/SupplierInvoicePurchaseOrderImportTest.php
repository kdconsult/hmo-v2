<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\Partner;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\SupplierInvoiceService;
use App\Services\TenantOnboardingService;

test('invoicedQuantity returns zero when no SI items linked', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $poItem = PurchaseOrderItem::factory()->create(['quantity' => '10.0000']);

        expect($poItem->invoicedQuantity())->toBe('0')
            ->and($poItem->remainingInvoiceableQuantity())->toBe('10.0000');
    });
});

test('invoicedQuantity excludes items on cancelled invoices', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $po = PurchaseOrder::factory()->create();
        $poItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Invoice confirmed — qty 3 should count
        $confirmedInvoice = SupplierInvoice::factory()->confirmed()->create();
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $confirmedInvoice->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => '3.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Invoice cancelled — qty 2 should NOT count
        $cancelledInvoice = SupplierInvoice::factory()->create(['status' => DocumentStatus::Cancelled]);
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $cancelledInvoice->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => '2.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        expect($poItem->invoicedQuantity())->toBe('3.0000')
            ->and($poItem->remainingInvoiceableQuantity())->toBe('7.0000');
    });
});

test('remainingInvoiceableQuantity subtracts all non-cancelled invoiced quantities', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $po = PurchaseOrder::factory()->create();
        $poItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '20.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $invoice1 = SupplierInvoice::factory()->confirmed()->create();
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice1->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $invoice2 = SupplierInvoice::factory()->draft()->create();
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice2->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => '8.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Both confirmed and draft count (only cancelled is excluded)
        expect($poItem->invoicedQuantity())->toBe('13.0000')
            ->and($poItem->remainingInvoiceableQuantity())->toBe('7.0000');
    });
});

test('import action creates SI items with correct field mapping', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $service = app(SupplierInvoiceService::class);
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $partner = Partner::factory()->supplier()->create();

        $po = PurchaseOrder::factory()->create(['partner_id' => $partner->id]);
        $poItem1 = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '5.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '10.00',
            'vat_rate_id' => $vatRate->id,
            'description' => 'Widget A',
            'sort_order' => 1,
        ]);
        $poItem2 = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '3.0000',
            'unit_price' => '50.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
            'description' => 'Widget B',
            'sort_order' => 2,
        ]);

        $invoice = SupplierInvoice::factory()->create([
            'partner_id' => $partner->id,
            'purchase_order_id' => $po->id,
            'pricing_mode' => PricingMode::VatExclusive,
            'amount_paid' => '0.00',
        ]);

        // Simulate the import action logic
        $existingPoItemIds = $invoice->items()
            ->whereNotNull('purchase_order_item_id')
            ->pluck('purchase_order_item_id')
            ->toArray();

        $poItems = PurchaseOrderItem::where('purchase_order_id', $invoice->purchase_order_id)
            ->whereNotIn('id', $existingPoItemIds)
            ->with(['productVariant', 'vatRate'])
            ->get()
            ->filter(fn (PurchaseOrderItem $item) => bccomp($item->remainingInvoiceableQuantity(), '0', 4) > 0);

        foreach ($poItems as $poItem) {
            $siItem = SupplierInvoiceItem::create([
                'supplier_invoice_id' => $invoice->id,
                'purchase_order_item_id' => $poItem->id,
                'product_variant_id' => $poItem->product_variant_id,
                'description' => $poItem->description
                    ?? $poItem->productVariant?->getTranslation('name', app()->getLocale()) ?? '',
                'quantity' => $poItem->remainingInvoiceableQuantity(),
                'unit_price' => $poItem->unit_price,
                'discount_percent' => $poItem->discount_percent,
                'vat_rate_id' => $poItem->vat_rate_id,
                'sort_order' => $poItem->sort_order,
            ]);
            $siItem->loadMissing(['supplierInvoice', 'vatRate']);
            $service->recalculateItemTotals($siItem);
        }
        $service->recalculateDocumentTotals($invoice);

        $invoice->refresh();
        expect($invoice->items()->count())->toBe(2);

        $si1 = $invoice->items()->where('purchase_order_item_id', $poItem1->id)->first();
        expect($si1)->not->toBeNull()
            ->and($si1->description)->toBe('Widget A')
            ->and((float) $si1->quantity)->toBe(5.0)
            ->and((float) $si1->unit_price)->toBe(100.0)
            ->and((float) $si1->discount_percent)->toBe(10.0)
            ->and($si1->vat_rate_id)->toBe($vatRate->id)
            ->and($si1->sort_order)->toBe(1)
            ->and((float) $si1->line_total)->toBeGreaterThan(0); // recalculation applied

        // Document totals updated
        expect((float) $invoice->total)->toBeGreaterThan(0);
    });
});

test('import is idempotent — skips PO items already linked to this invoice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $po = PurchaseOrder::factory()->create();
        $poItem1 = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);
        $poItem2 = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $invoice = SupplierInvoice::factory()->create([
            'purchase_order_id' => $po->id,
            'amount_paid' => '0.00',
        ]);

        // Pre-link poItem1 to this invoice
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_order_item_id' => $poItem1->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Run import — should only add poItem2
        $existingPoItemIds = $invoice->items()
            ->whereNotNull('purchase_order_item_id')
            ->pluck('purchase_order_item_id')
            ->toArray();

        $poItems = PurchaseOrderItem::where('purchase_order_id', $invoice->purchase_order_id)
            ->whereNotIn('id', $existingPoItemIds)
            ->get()
            ->filter(fn (PurchaseOrderItem $item) => bccomp($item->remainingInvoiceableQuantity(), '0', 4) > 0);

        foreach ($poItems as $poItem) {
            SupplierInvoiceItem::create([
                'supplier_invoice_id' => $invoice->id,
                'purchase_order_item_id' => $poItem->id,
                'product_variant_id' => $poItem->product_variant_id,
                'description' => $poItem->description ?? '',
                'quantity' => $poItem->remainingInvoiceableQuantity(),
                'unit_price' => $poItem->unit_price,
                'discount_percent' => $poItem->discount_percent,
                'vat_rate_id' => $poItem->vat_rate_id,
                'sort_order' => $poItem->sort_order,
            ]);
        }

        expect($invoice->items()->count())->toBe(2); // poItem1 already there + poItem2 added
        expect($invoice->items()->where('purchase_order_item_id', $poItem2->id)->exists())->toBeTrue();
    });
});

test('import skips fully-invoiced PO items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $po = PurchaseOrder::factory()->create();
        $poItemFull = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);
        $poItemPartial = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Fully invoice poItemFull on a different invoice
        $otherInvoice = SupplierInvoice::factory()->confirmed()->create();
        SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $otherInvoice->id,
            'purchase_order_item_id' => $poItemFull->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // New invoice for same PO
        $invoice = SupplierInvoice::factory()->create([
            'purchase_order_id' => $po->id,
            'amount_paid' => '0.00',
        ]);

        $poItems = PurchaseOrderItem::where('purchase_order_id', $invoice->purchase_order_id)
            ->get()
            ->filter(fn (PurchaseOrderItem $item) => bccomp($item->remainingInvoiceableQuantity(), '0', 4) > 0);

        foreach ($poItems as $poItem) {
            SupplierInvoiceItem::create([
                'supplier_invoice_id' => $invoice->id,
                'purchase_order_item_id' => $poItem->id,
                'product_variant_id' => $poItem->product_variant_id,
                'description' => $poItem->description ?? '',
                'quantity' => $poItem->remainingInvoiceableQuantity(),
                'unit_price' => $poItem->unit_price,
                'discount_percent' => $poItem->discount_percent,
                'vat_rate_id' => $poItem->vat_rate_id,
                'sort_order' => $poItem->sort_order,
            ]);
        }

        // Only poItemPartial should have been imported
        expect($invoice->items()->count())->toBe(1);
        expect($invoice->items()->where('purchase_order_item_id', $poItemFull->id)->exists())->toBeFalse();
        expect($invoice->items()->where('purchase_order_item_id', $poItemPartial->id)->exists())->toBeTrue();
    });
});

test('description falls back to variant name when PO item description is null', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $variant = ProductVariant::factory()->create();
        $po = PurchaseOrder::factory()->create();
        $poItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'description' => null,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $invoice = SupplierInvoice::factory()->create([
            'purchase_order_id' => $po->id,
            'amount_paid' => '0.00',
        ]);

        $poItem->load('productVariant');
        $expectedDescription = $poItem->productVariant?->getTranslation('name', app()->getLocale()) ?? '';

        $siItem = SupplierInvoiceItem::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_order_item_id' => $poItem->id,
            'product_variant_id' => $poItem->product_variant_id,
            'description' => $poItem->description
                ?? $poItem->productVariant?->getTranslation('name', app()->getLocale()) ?? '',
            'quantity' => $poItem->remainingInvoiceableQuantity(),
            'unit_price' => $poItem->unit_price,
            'discount_percent' => $poItem->discount_percent,
            'vat_rate_id' => $poItem->vat_rate_id,
            'sort_order' => $poItem->sort_order,
        ]);

        expect($siItem->description)->toBe($expectedDescription)
            ->and($siItem->description)->not->toBeEmpty();
    });
});
