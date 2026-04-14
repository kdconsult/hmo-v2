<?php

use App\Models\Category;
use App\Models\CompanySettings;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

afterEach(function () {
    tenancy()->end();
});

it('can create product without category when setting is disabled', function () {
    $this->tenant->run(function () {
        $product = Product::factory()->stock()->create(['category_id' => null]);

        expect($product->category_id)->toBeNull();
    });
});

it('can create product with category when setting is enabled', function () {
    $this->tenant->run(function () {
        CompanySettings::set('catalog', 'require_product_category', '1');

        $category = Category::factory()->create();
        $product = Product::factory()->stock()->create(['category_id' => $category->id]);

        expect($product->category_id)->toBe($category->id);
    });
});

it('setting reads back as boolean correctly', function () {
    $this->tenant->run(function () {
        expect((bool) CompanySettings::get('catalog', 'require_product_category', false))->toBeFalse();

        CompanySettings::set('catalog', 'require_product_category', '1');

        expect((bool) CompanySettings::get('catalog', 'require_product_category', false))->toBeTrue();
    });
});
