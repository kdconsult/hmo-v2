<?php

use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseReturnService;
use App\Services\TenantOnboardingService;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

afterEach(function () {
    tenancy()->end();
});

it('stores GRN reference on stock movements created by GRN confirmation', function () {
    $this->tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $product = Product::factory()->stock()->create();

        $grn = GoodsReceivedNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'product_variant_id' => $product->defaultVariant->id,
            'quantity' => '5.0000',
            'unit_cost' => '10.0000',
        ]);

        app(GoodsReceiptService::class)->confirm($grn);

        $movement = StockMovement::where('product_variant_id', $product->defaultVariant->id)->first();

        expect($movement->reference_type)->toBe('goods_received_note')
            ->and($movement->reference_id)->toBe($grn->id)
            ->and($movement->reference->grn_number)->toBe($grn->grn_number);
    });
});

it('stores purchase return reference on stock movements created by return confirmation', function () {
    $this->tenant->run(function () {
        $warehouse = Warehouse::where('code', 'MAIN')->first();
        $product = Product::factory()->stock()->create();

        // First receive stock via GRN
        $grn = GoodsReceivedNote::factory()->draft()->create(['warehouse_id' => $warehouse->id]);
        $grnItem = GoodsReceivedNoteItem::factory()->create([
            'goods_received_note_id' => $grn->id,
            'product_variant_id' => $product->defaultVariant->id,
            'quantity' => '10.0000',
            'unit_cost' => '10.0000',
        ]);
        app(GoodsReceiptService::class)->confirm($grn);

        // Then create and confirm a purchase return
        $pr = PurchaseReturn::factory()->draft()->create([
            'warehouse_id' => $warehouse->id,
        ]);
        PurchaseReturnItem::factory()->create([
            'purchase_return_id' => $pr->id,
            'goods_received_note_item_id' => $grnItem->id,
            'product_variant_id' => $product->defaultVariant->id,
            'quantity' => '3.0000',
            'unit_cost' => '10.0000',
        ]);

        app(PurchaseReturnService::class)->confirm($pr);

        $returnMovement = StockMovement::where('product_variant_id', $product->defaultVariant->id)
            ->where('quantity', '<', 0)
            ->first();

        expect($returnMovement->reference_type)->toBe('purchase_return')
            ->and($returnMovement->reference_id)->toBe($pr->id)
            ->and($returnMovement->reference->pr_number)->toBe($pr->pr_number);
    });
});
