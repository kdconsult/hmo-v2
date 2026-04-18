<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Enums\SalesReturnStatus;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesReturnService;
use App\Services\StockService;
use App\Services\TenantOnboardingService;

/**
 * Bootstrap: create a confirmed DN with one item that has reserved + delivered stock.
 * Returns [$sr, $variant, $warehouse, $dnItem] ready for SalesReturnService.
 */
function makeConfirmedDnWithStock(string $quantity = '10.0000'): array
{
    $product = Product::factory()->stock()->create();
    $variant = $product->defaultVariant;
    $warehouse = Warehouse::where('code', 'MAIN')->first();

    // Simulate stock that was issued for delivery (stock is now out)
    app(StockService::class)->receive($variant, $warehouse, $quantity);
    app(StockService::class)->issue($variant, $warehouse, $quantity);

    $dn = DeliveryNote::factory()->confirmed()->create([
        'warehouse_id' => $warehouse->id,
    ]);

    $dnItem = DeliveryNoteItem::factory()->create([
        'delivery_note_id' => $dn->id,
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'unit_cost' => '50.0000',
    ]);

    $sr = SalesReturn::factory()->draft()->create([
        'delivery_note_id' => $dn->id,
        'partner_id' => $dn->partner_id,
        'warehouse_id' => $warehouse->id,
    ]);

    SalesReturnItem::factory()->create([
        'sales_return_id' => $sr->id,
        'delivery_note_item_id' => $dnItem->id,
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'unit_cost' => '50.0000',
    ]);

    return [$sr, $variant, $warehouse, $dnItem];
}

test('confirming a sales return receives stock back into the warehouse', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        [$sr, $variant, $warehouse] = makeConfirmedDnWithStock('10.0000');

        app(SalesReturnService::class)->confirm($sr);

        $stockItem = $variant->stockItems()->where('warehouse_id', $warehouse->id)->first();
        expect((float) $stockItem->quantity)->toBe(10.0);
    });
});

test('confirming a sales return creates a stock movement with SalesReturn type', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        [$sr, $variant] = makeConfirmedDnWithStock('5.0000');

        app(SalesReturnService::class)->confirm($sr);

        $sr->refresh();
        $movement = $sr->stockMovements()->first();

        expect($movement)->not->toBeNull()
            ->and($movement->type)->toBe(MovementType::SalesReturn)
            ->and((float) $movement->quantity)->toBe(5.0);
    });
});

test('confirming a sales return fails when there are no items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $sr = SalesReturn::factory()->draft()->create();

        expect(fn () => app(SalesReturnService::class)->confirm($sr))
            ->toThrow(InvalidArgumentException::class, 'no items');
    });
});

test('confirming an already confirmed sales return throws', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $sr = SalesReturn::factory()->confirmed()->create();

        expect(fn () => app(SalesReturnService::class)->confirm($sr))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('confirmed sales return is not editable', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        [$sr] = makeConfirmedDnWithStock();

        app(SalesReturnService::class)->confirm($sr);
        $sr->refresh();

        expect($sr->isEditable())->toBeFalse()
            ->and($sr->status)->toBe(SalesReturnStatus::Confirmed);
    });
});

test('draft sales return can be cancelled', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $sr = SalesReturn::factory()->draft()->create();

        app(SalesReturnService::class)->cancel($sr);
        $sr->refresh();

        expect($sr->status)->toBe(SalesReturnStatus::Cancelled);
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

        $dn = DeliveryNote::factory()->confirmed()->create([
            'warehouse_id' => $warehouse->id,
        ]);
        $dnItem = DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.0000',
            'unit_cost' => '50.0000',
        ]);

        // Confirmed return of 4 units — should count
        $confirmedSr = SalesReturn::factory()->confirmed()->create([
            'delivery_note_id' => $dn->id,
            'partner_id' => $dn->partner_id,
            'warehouse_id' => $warehouse->id,
        ]);
        SalesReturnItem::factory()->create([
            'sales_return_id' => $confirmedSr->id,
            'delivery_note_item_id' => $dnItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '4.0000',
            'unit_cost' => '50.0000',
        ]);

        // Cancelled return of 5 units — must NOT count
        $cancelledSr = SalesReturn::factory()->cancelled()->create([
            'delivery_note_id' => $dn->id,
            'partner_id' => $dn->partner_id,
            'warehouse_id' => $warehouse->id,
        ]);
        SalesReturnItem::factory()->create([
            'sales_return_id' => $cancelledSr->id,
            'delivery_note_item_id' => $dnItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
            'unit_cost' => '50.0000',
        ]);

        $dnItem->refresh();

        expect($dnItem->returnedQuantity())->toBe('4.0000')
            ->and($dnItem->remainingReturnableQuantity())->toBe('6.0000');
    });
});

test('autoFillItemsFromDeliveryNote creates sales return items from delivery note items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $dn = DeliveryNote::factory()->confirmed()->create([
            'warehouse_id' => $warehouse->id,
        ]);
        $dnItem = DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '8.0000',
            'unit_cost' => '25.0000',
        ]);

        $sr = SalesReturn::factory()->draft()->create([
            'delivery_note_id' => $dn->id,
            'partner_id' => $dn->partner_id,
            'warehouse_id' => $warehouse->id,
        ]);

        app(SalesReturnService::class)->autoFillItemsFromDeliveryNote($sr);

        $items = $sr->items()->get();

        expect($items)->toHaveCount(1);

        $item = $items->first();
        expect($item->delivery_note_item_id)->toBe($dnItem->id)
            ->and($item->product_variant_id)->toBe($variant->id)
            ->and((string) $item->quantity)->toBe('8.0000')
            ->and((string) $item->unit_cost)->toBe('25.0000');
    });
});

test('autoFillItemsFromDeliveryNote skips delivery note items already fully returned', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $variant = $product->defaultVariant;
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        $dn = DeliveryNote::factory()->confirmed()->create([
            'warehouse_id' => $warehouse->id,
        ]);
        $dnItem = DeliveryNoteItem::factory()->create([
            'delivery_note_id' => $dn->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
            'unit_cost' => '10.0000',
        ]);

        // Fully return the item via an existing confirmed SR
        $existingSr = SalesReturn::factory()->create([
            'delivery_note_id' => $dn->id,
            'status' => SalesReturnStatus::Confirmed,
        ]);
        SalesReturnItem::factory()->create([
            'sales_return_id' => $existingSr->id,
            'delivery_note_item_id' => $dnItem->id,
            'product_variant_id' => $variant->id,
            'quantity' => '5.0000',
        ]);

        $newSr = SalesReturn::factory()->draft()->create([
            'delivery_note_id' => $dn->id,
            'partner_id' => $dn->partner_id,
            'warehouse_id' => $warehouse->id,
        ]);

        app(SalesReturnService::class)->autoFillItemsFromDeliveryNote($newSr);

        expect($newSr->items()->count())->toBe(0);
    });
});
