<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\TenantOnboardingService;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

afterEach(function () {
    tenancy()->end();
});

it('clones a product with modified code', function () {
    $this->tenant->run(function () {
        $original = Product::factory()->stock()->create(['code' => 'WIDGET-001']);

        $replica = $original->replicate(['barcode']);
        $replica->code = $original->code.'-COPY';
        $replica->save();

        expect(Product::where('code', 'WIDGET-001-COPY')->exists())->toBeTrue();
        expect($replica->name)->toBe($original->name);
        expect($replica->type->value)->toBe($original->type->value);
        expect($replica->sale_price)->toBe($original->sale_price);
    });
});

it('auto-creates default variant on clone via boot event', function () {
    $this->tenant->run(function () {
        $original = Product::factory()->stock()->create(['code' => 'PROD-001']);

        $replica = $original->replicate(['barcode']);
        $replica->code = 'PROD-001-COPY';
        $replica->save();

        $defaultVariants = ProductVariant::where('product_id', $replica->id)
            ->where('is_default', true)
            ->count();

        expect($defaultVariants)->toBe(1);
    });
});

it('clones non-default variants with modified sku', function () {
    $this->tenant->run(function () {
        $original = Product::factory()->stock()->create(['code' => 'PROD-002']);

        // Add 2 non-default variants
        ProductVariant::factory()->create(['product_id' => $original->id, 'is_default' => false, 'sku' => 'VAR-RED']);
        ProductVariant::factory()->create(['product_id' => $original->id, 'is_default' => false, 'sku' => 'VAR-BLUE']);

        $replica = $original->replicate(['barcode']);
        $replica->code = 'PROD-002-COPY';
        $replica->save();

        // Clone non-default variants (boot event already created the default)
        $original->variants()
            ->where('is_default', false)
            ->get()
            ->each(function ($variant) use ($replica) {
                $clone = $variant->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                $clone->product_id = $replica->id;
                $clone->sku = ($variant->sku ?? '').'-COPY';
                $clone->save();
            });

        $allVariants = ProductVariant::where('product_id', $replica->id)->count();
        $nonDefaultVariants = ProductVariant::where('product_id', $replica->id)->where('is_default', false)->count();

        expect($allVariants)->toBe(3) // 1 default (boot) + 2 cloned
            ->and($nonDefaultVariants)->toBe(2)
            ->and(ProductVariant::where('product_id', $replica->id)->where('sku', 'VAR-RED-COPY')->exists())->toBeTrue()
            ->and(ProductVariant::where('product_id', $replica->id)->where('sku', 'VAR-BLUE-COPY')->exists())->toBeTrue();
    });
});

it('does not clone stock items or movements', function () {
    $this->tenant->run(function () {
        $original = Product::factory()->stock()->create(['code' => 'PROD-003']);
        $warehouse = Warehouse::where('code', 'MAIN')->first();

        app(StockService::class)->receive(
            variant: $original->defaultVariant,
            warehouse: $warehouse,
            quantity: '10.0000',
        );

        $replica = $original->replicate(['barcode']);
        $replica->code = 'PROD-003-COPY';
        $replica->save();

        $replicaDefaultVariant = ProductVariant::where('product_id', $replica->id)
            ->where('is_default', true)
            ->first();

        expect((float) $replicaDefaultVariant->stockItems()->sum('quantity'))->toBe(0.0);
    });
});

it('preserves translatable fields on clone', function () {
    $this->tenant->run(function () {
        $original = Product::factory()->stock()->create([
            'code' => 'PROD-004',
            'name' => ['en' => 'Widget', 'bg' => 'Джаджа'],
        ]);

        $replica = $original->replicate(['barcode']);
        $replica->code = 'PROD-004-COPY';
        $replica->save();

        expect($replica->getTranslation('name', 'en'))->toBe('Widget')
            ->and($replica->getTranslation('name', 'bg'))->toBe('Джаджа');
    });
});
