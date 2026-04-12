<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;

test('category can be created with translatable name', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $category = Category::create([
            'name' => ['en' => 'Electronics'],
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        expect($category->getTranslation('name', 'en'))->toBe('Electronics');
    });
});

test('slug is auto-generated from name if not provided', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $category = Category::create([
            'name' => ['en' => 'Office Supplies'],
            'is_active' => true,
        ]);

        expect($category->slug)->toBe('office-supplies');
    });
});

test('root category has depth 0', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $root = Category::factory()->root()->create();
        expect($root->depthLevel())->toBe(0);
    });
});

test('child category has depth 1', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $child = Category::factory()->child()->create();
        expect($child->depthLevel())->toBe(1);
    });
});

test('grandchild category has depth 2', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $grandchild = Category::factory()->grandchild()->create();
        expect($grandchild->depthLevel())->toBe(2);
    });
});

test('creating a 4th level category throws InvalidArgumentException', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $grandchild = Category::factory()->grandchild()->create();

        expect(fn () => Category::create([
            'name' => ['en' => 'Too Deep'],
            'slug' => 'too-deep',
            'parent_id' => $grandchild->id,
            'is_active' => true,
        ]))->toThrow(InvalidArgumentException::class);
    });
});

test('roots scope returns only root categories', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $parent = Category::factory()->root()->create();
        Category::factory()->root()->create();
        // Create child attached to the already-created parent
        Category::factory()->create(['parent_id' => $parent->id]);

        expect(Category::roots()->count())->toBe(2);
    });
});

test('active scope filters inactive categories', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        Category::factory()->root()->count(2)->create();
        Category::factory()->root()->inactive()->create();

        expect(Category::active()->count())->toBe(2);
    });
});

test('withChildren scope eager loads children relationship', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $parent = Category::factory()->root()->create();
        Category::factory()->create(['parent_id' => $parent->id]);
        Category::factory()->create(['parent_id' => $parent->id]);

        $categories = Category::withChildren()->roots()->get();
        $loadedParent = $categories->first();

        expect($loadedParent->relationLoaded('children'))->toBeTrue()
            ->and($loadedParent->children->count())->toBe(2);
    });
});

test('category can be soft deleted and restored', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $category = Category::factory()->root()->create();
        $category->delete();

        expect(Category::count())->toBe(0);

        $category->restore();
        expect(Category::count())->toBe(1);
    });
});
