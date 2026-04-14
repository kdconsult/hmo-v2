<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\VatRate;
use App\Services\TenantOnboardingService;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

afterEach(function () {
    tenancy()->end();
});

test('resolveDefault returns own value when set', function () {
    $this->tenant->run(function () {
        $vatRate = VatRate::factory()->create();
        $category = Category::factory()->withDefaultVatRate($vatRate)->root()->create();

        expect($category->resolveDefault('default_vat_rate_id'))->toBe($vatRate->id);
    });
});

test('resolveDefault returns null when no ancestor has a value', function () {
    $this->tenant->run(function () {
        $category = Category::factory()->root()->create();

        expect($category->resolveDefault('default_vat_rate_id'))->toBeNull();
    });
});

test('resolveDefault inherits from parent when own value is null', function () {
    $this->tenant->run(function () {
        $vatRate = VatRate::factory()->create();
        $parent = Category::factory()->withDefaultVatRate($vatRate)->root()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $child->load('parent');

        expect($child->resolveDefault('default_vat_rate_id'))->toBe($vatRate->id);
    });
});

test('resolveDefault prefers own value over parent value', function () {
    $this->tenant->run(function () {
        $rateA = VatRate::factory()->create();
        $rateB = VatRate::factory()->create();
        $parent = Category::factory()->withDefaultVatRate($rateA)->root()->create();
        $child = Category::factory()->withDefaultVatRate($rateB)->create(['parent_id' => $parent->id]);
        $child->load('parent');

        expect($child->resolveDefault('default_vat_rate_id'))->toBe($rateB->id);
    });
});

test('resolveDefault walks grandparent chain', function () {
    $this->tenant->run(function () {
        $vatRate = VatRate::factory()->create();
        $root = Category::factory()->withDefaultVatRate($vatRate)->root()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);
        $grandchild->load('parent.parent');

        expect($grandchild->resolveDefault('default_vat_rate_id'))->toBe($vatRate->id);
    });
});

test('resolveDefault throws for disallowed attribute', function () {
    $this->tenant->run(function () {
        $category = Category::factory()->root()->create();

        expect(fn () => $category->resolveDefault('name'))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('resolveDefault works for default_unit_id', function () {
    $this->tenant->run(function () {
        $unit = Unit::factory()->create();
        $parent = Category::factory()->withDefaultUnit($unit)->root()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $child->load('parent');

        expect($child->resolveDefault('default_unit_id'))->toBe($unit->id);
    });
});
