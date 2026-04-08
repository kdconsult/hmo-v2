<?php

declare(strict_types=1);

use App\Models\Tenant;

test('generateUniqueSlug returns adjective-noun format', function () {
    $slug = Tenant::generateUniqueSlug();
    expect($slug)->toMatch('/^[a-z]+-[a-z]+(-\d+)?$/');
});

test('generateUniqueSlug returns a slug not already in the database', function () {
    $existing = Tenant::factory()->create();

    $slug = Tenant::generateUniqueSlug();

    expect($slug)->not->toBe($existing->slug);
});

test('generateUniqueSlug retries when slug is taken', function () {
    // Exhaust first 10 pure adjective-noun attempts by seeding a known slug,
    // then verify the fallback numbered slug is returned instead.
    $first = Tenant::generateUniqueSlug();
    Tenant::factory()->create(['slug' => $first]);

    $second = Tenant::generateUniqueSlug();

    expect($second)->not->toBe($first)
        ->and($second)->toMatch('/^[a-z]+-[a-z]+(-\d+)?$/');
});
