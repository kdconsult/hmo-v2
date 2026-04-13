<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Enums\PurchaseReturnStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseReturnService;
use App\Services\StockService;
use App\Services\TenantOnboardingService;

/**
 * Bootstrap: create a confirmed GRN with one item that has stock received.
 * Returns [$pr, $variant, $warehouse] ready for PurchaseReturnService.
 */
function makeConfirmedGrnWithStock(string $quantity = '10.0000'): array
{
    $product = Product::factory()->stock()->create();
    $variant = $product->defaultVariant;
    $warehouse = Warehouse::where('code', 'MAIN')->first();

    // Put stock in
    app(StockService::class)->receive($variant, $warehouse, $quantity);

    $grn = GoodsReceivedNote::factory()->confirmed()->create([
        'warehouse_id' => $warehouse->id,
    ]);

    $grnItem = GoodsReceivedNoteItem::factory()->create([
        'goods_received_note_id' => $grn->id,
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'unit_cost' => '50.0000',
    ]);

    $pr = PurchaseReturn::factory()->draft()->create([
        'goods_received_note_id' => $grn->id,
        'partner_id' => $grn->partner_id,
        'warehouse_id' => $warehouse->id,
    ]);

    PurchaseReturnItem::factory()->create([
        'purchase_return_id' => $pr->id,
        'goods_received_note_item_id' => $grnItem->id,
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'unit_cost' => '50.0000',
    ]);

    return [$pr, $variant, $warehouse, $grnItem];
}

test('confirming a purchase return issues stock from the warehouse', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        [$pr, $variant, $warehouse] = makeConfirmedGrnWithStock('10.0000');

        app(PurchaseReturnService::class)->confirm($pr);

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();
        expect((float) $stockItem->quantity)->toBe(0.0);
    });
});

test('confirming a purchase return creates a stock movement with PurchaseReturn type', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        [$pr, $variant] = makeConfirmedGrnWithStock('5.0000');

        app(PurchaseReturnService::class)->confirm($pr);

        $pr->refresh();
        $movement = $pr->stockMovements()->first();

        expect($movement)->not->toBeNull()
            ->and($movement->type)->toBe(MovementType::PurchaseReturn)
            ->and((float) $movement->quantity)->toBe(-5.0);
    });
});

test('confirming a purchase return fails when there are no items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $pr = PurchaseReturn::factory()->draft()->create();

        expect(fn () => app(PurchaseReturnService::class)->confirm($pr))
            ->toThrow(InvalidArgumentException::class, 'no items');
    });
});

test('confirming an already confirmed purchase return throws', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $pr = PurchaseReturn::factory()->confirmed()->create();

        expect(fn () => app(PurchaseReturnService::class)->confirm($pr))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('confirmed purchase return is not editable', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        [$pr] = makeConfirmedGrnWithStock();

        app(PurchaseReturnService::class)->confirm($pr);
        $pr->refresh();

        expect($pr->isEditable())->toBeFalse()
            ->and($pr->status)->toBe(PurchaseReturnStatus::Confirmed);
    });
});

test('draft purchase return can be cancelled', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $pr = PurchaseReturn::factory()->draft()->create();

        app(PurchaseReturnService::class)->cancel($pr);
        $pr->refresh();

        expect($pr->status)->toBe(PurchaseReturnStatus::Cancelled);
    });
});

test('remaining returnable quantity excludes cancelled returns', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        app(StockService::class)->receive($variant, $warehouse, '10.0000');

        $grn = GoodsReceivedNote::factory()->confirmed()->create([
            'warehouse_id' => $warehouse->id,
        ]);
        $grnItem = GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'unit_cost' => '10.0000',
        ]);

        // Confirmed return of 4 units — should count
        $confirmedPr = PurchaseReturn::factory()->confirmed()->create([
            'goods_received_note_id' => $grn->id,
            'partner_id' => $grn->partner_id,
            'warehouse_id' => $warehouse->id,
        ]);
        PurchaseReturnItem::factory()->create([
            'purchase_return_id' => $confirmedPr->id,
            'goods_received_note_item_id' => $grnItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '4.0000',
            'unit_cost' => '10.0000',
        ]);

        // Cancelled return of 5 units — must NOT count
        $cancelledPr = PurchaseReturn::factory()->cancelled()->create([
            'goods_received_note_id' => $grn->id,
            'partner_id' => $grn->partner_id,
            'warehouse_id' => $warehouse->id,
        ]);
        PurchaseReturnItem::factory()->create([
            'purchase_return_id' => $cancelledPr->id,
            'goods_received_note_item_id' => $grnItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
            'unit_cost' => '10.0000',
        ]);

        $grnItem->refresh();

        expect($grnItem->returnedQuantity())->toBe('4.0000')
            ->and($grnItem->remainingReturnableQuantity())->toBe('6.0000');
    });
});

test('cannot return more than the received quantity', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        app(StockService::class)->receive($variant, $warehouse, '10.0000');

        $grn = GoodsReceivedNote::factory()->confirmed()->create([
            'warehouse_id' => $warehouse->id,
        ]);
        $grnItem = GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
            'unit_cost' => '10.0000',
        ]);

        expect($grnItem->remainingReturnableQuantity())->toBe('5.0000');
    });
});

test('confirming a purchase return fails with insufficient stock', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        // Stock only has 3 but we try to return 10
        app(StockService::class)->receive($variant, $warehouse, '3.0000');

        $grn = GoodsReceivedNote::factory()->confirmed()->create([
            'warehouse_id' => $warehouse->id,
        ]);
        $grnItem = GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'unit_cost' => '50.0000',
        ]);

        $pr = PurchaseReturn::factory()->draft()->create([
            'goods_received_note_id' => $grn->id,
            'partner_id' => $grn->partner_id,
            'warehouse_id' => $warehouse->id,
        ]);
        PurchaseReturnItem::factory()->create([
            'purchase_return_id' => $pr->id,
            'goods_received_note_item_id' => $grnItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'unit_cost' => '50.0000',
        ]);

        expect(fn () => app(PurchaseReturnService::class)->confirm($pr))
            ->toThrow(InsufficientStockException::class);
    });
});
