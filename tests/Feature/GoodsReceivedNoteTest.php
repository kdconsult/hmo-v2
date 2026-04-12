<?php

declare(strict_types=1);

use App\Enums\GoodsReceivedNoteStatus;
use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\TenantOnboardingService;

test('confirming GRN receives stock into warehouse', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $grn = GoodsReceivedNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
        ]);

        app(GoodsReceiptService::class)->confirm($grn);

        $grn->refresh();
        expect($grn->status)->toBe(GoodsReceivedNoteStatus::Confirmed)
            ->and($grn->received_at)->not->toBeNull();

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();
        expect($stockItem)->not->toBeNull()
            ->and((float) $stockItem->quantity)->toBe(5.0);
    });
});

test('confirming GRN creates stock movement with GRN as morph reference', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $grn = GoodsReceivedNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '3.0000',
        ]);

        app(GoodsReceiptService::class)->confirm($grn);

        $movement = StockMovement::first();
        expect($movement->type)->toBe(MovementType::Purchase)
            ->and($movement->reference_type)->toBe('goods_received_note')
            ->and($movement->reference_id)->toBe($grn->id);
    });
});

test('confirming GRN fails when no items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $grn = GoodsReceivedNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);

        expect(fn () => app(GoodsReceiptService::class)->confirm($grn))
            ->toThrow(InvalidArgumentException::class, 'no items');
    });
});

test('confirming already confirmed GRN throws exception', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $grn = GoodsReceivedNote::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);

        expect(fn () => app(GoodsReceiptService::class)->confirm($grn))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('confirming GRN linked to PO updates PO received quantities and status', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);

        $po = PurchaseOrder::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);
        $poItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'quantity_received' => '0.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $grn = GoodsReceivedNote::factory()->draft()->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
        ]);
        GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '6.0000',
        ]);

        app(GoodsReceiptService::class)->confirm($grn);

        $poItem->refresh();
        $po->refresh();

        expect((float) $poItem->quantity_received)->toBe(6.0)
            ->and($po->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
    });
});

test('confirming second GRN completing all items marks PO as Received', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);

        $po = PurchaseOrder::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);
        $poItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'quantity_received' => '0.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $service = app(GoodsReceiptService::class);

        // First GRN: receive 6 of 10
        $grn1 = GoodsReceivedNote::factory()->draft()->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
        ]);
        GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn1->id,
            'purchase_order_item_id' => $poItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '6.0000',
        ]);
        $service->confirm($grn1);

        // Second GRN: receive remaining 4
        $grn2 = GoodsReceivedNote::factory()->draft()->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
        ]);
        GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn2->id,
            'purchase_order_item_id' => $poItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '4.0000',
        ]);
        $service->confirm($grn2);

        $po->refresh();
        expect($po->status)->toBe(PurchaseOrderStatus::Received);
    });
});

test('standalone GRN (no PO) confirms correctly without error', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $grn = GoodsReceivedNote::factory()->draft()->create([
            'purchase_order_id' => null,
            'warehouse_id' => $warehouse->id,
        ]);
        GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '2.0000',
        ]);

        app(GoodsReceiptService::class)->confirm($grn);

        expect($grn->fresh()->status)->toBe(GoodsReceivedNoteStatus::Confirmed);
    });
});

test('confirmed GRN is not editable', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $grn = GoodsReceivedNote::factory()->confirmed()->create(['warehouse_id' => $warehouse->id]);

        expect($grn->isEditable())->toBeFalse()
            ->and($grn->isConfirmed())->toBeTrue();
    });
});

test('draft GRN can be cancelled', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $grn = GoodsReceivedNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);

        app(GoodsReceiptService::class)->cancel($grn);

        expect($grn->fresh()->status)->toBe(GoodsReceivedNoteStatus::Cancelled);
    });
});
