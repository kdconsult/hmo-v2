<?php

declare(strict_types=1);

use App\Support\TenantSlugGenerator;

test('generate returns adjective-noun format', function () {
    $slug = TenantSlugGenerator::generate();
    expect($slug)->toMatch('/^[a-z]+-[a-z]+$/');
});

test('generate uses only valid dns label characters', function () {
    foreach (range(1, 50) as $_) {
        expect(TenantSlugGenerator::generate())->toMatch('/^[a-z0-9-]+$/');
    }
});

test('generate produces varying results', function () {
    $slugs = array_map(fn () => TenantSlugGenerator::generate(), range(1, 30));
    expect(count(array_unique($slugs)))->toBeGreaterThan(1);
});
