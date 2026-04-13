<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\TenantOnboardingService;

test('product can be created with translatable name', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create([
            'name' => ['en' => 'Test Widget'],
        ]);

        expect($product->getTranslation('name', 'en'))->toBe('Test Widget');
    });
});

test('product auto-creates a default variant on create', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create(['code' => 'PROD-001']);

        expect($product->variants()->count())->toBe(1)
            ->and($product->defaultVariant)->not->toBeNull()
            ->and($product->defaultVariant->is_default)->toBeTrue()
            ->and($product->defaultVariant->sku)->toBe('PROD-001');
    });
});

test('stock product defaults is_stockable to true', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        expect($product->is_stockable)->toBeTrue();
    });
});

test('service product defaults is_stockable to false', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->service()->create();
        expect($product->is_stockable)->toBeFalse();
    });
});

test('bundle product defaults is_stockable to true', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->bundle()->create();
        expect($product->is_stockable)->toBeTrue();
    });
});

test('hasVariants returns false with only default variant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        expect($product->hasVariants())->toBeFalse();
    });
});

test('hasVariants returns true when non-default active variants exist', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        ProductVariant::create([
            'product_id' => $product->id,
            'name' => ['en' => 'Red / Large'],
            'sku' => 'PROD-001-R-L',
            'is_default' => false,
            'is_active' => true,
        ]);

        expect($product->hasVariants())->toBeTrue();
    });
});

test('variant effective prices fall back to product prices', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create([
            'purchase_price' => '10.0000',
            'sale_price' => '15.0000',
        ]);

        $defaultVariant = $product->defaultVariant;
        expect($defaultVariant->effectivePurchasePrice())->toBe('10.0000')
            ->and($defaultVariant->effectiveSalePrice())->toBe('15.0000');
    });
});

test('variant own prices take precedence over product prices', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create([
            'purchase_price' => '10.0000',
            'sale_price' => '15.0000',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => ['en' => 'Blue'],
            'sku' => 'SKU-BLUE',
            'purchase_price' => '12.0000',
            'sale_price' => '18.0000',
            'is_default' => false,
            'is_active' => true,
        ]);

        expect($variant->effectivePurchasePrice())->toBe('12.0000')
            ->and($variant->effectiveSalePrice())->toBe('18.0000');
    });
});

test('product can be associated with a vat rate', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create();
        $product = Product::factory()->stock()->create(['vat_rate_id' => $vatRate->id]);

        expect($product->vatRate->id)->toBe($vatRate->id);
    });
});

test('product barcode field stores and retrieves correctly', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create(['barcode' => '1234567890123']);

        expect($product->fresh()->barcode)->toBe('1234567890123');
    });
});

test('product can be soft deleted and restored', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create();
        $product->delete();

        expect(Product::count())->toBe(0);

        $product->restore();
        expect(Product::count())->toBe(1);
    });
});

test('product type enum is cast correctly', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->service()->create();
        expect($product->type)->toBe(ProductType::Service);
    });
});

test('default variant name is stored as translatable structure', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create([
            'name' => ['en' => 'Bolt M8', 'bg' => 'Болт М8'],
        ]);

        $defaultVariant = $product->defaultVariant;

        expect($defaultVariant->getTranslation('name', 'en'))->toBe('Bolt M8')
            ->and($defaultVariant->getTranslation('name', 'bg'))->toBe('Болт М8');
    });
});

test('default variant is excluded from variant options when named variants exist', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create(['code' => 'SHIRT-001']);
        $defaultVariant = $product->defaultVariant;

        $namedVariant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => ['en' => 'Size S'],
            'sku' => 'SHIRT-001-S',
            'is_default' => false,
            'is_active' => true,
        ]);

        $options = ProductVariant::variantOptionsForSelect();

        expect(array_key_exists($defaultVariant->id, $options))->toBeFalse()
            ->and(array_key_exists($namedVariant->id, $options))->toBeTrue();
    });
});

test('default variant is included in options when product has no named variants', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $product = Product::factory()->stock()->create(['code' => 'BOLT-M8']);
        $defaultVariant = $product->defaultVariant;

        $options = ProductVariant::variantOptionsForSelect();

        expect(array_key_exists($defaultVariant->id, $options))->toBeTrue();
    });
});
