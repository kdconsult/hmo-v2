<?php

declare(strict_types=1);

use App\Support\TenantUrl;

beforeEach(function () {
    config(['app.domain' => 'hmo.test']);
});

test('TenantUrl::to builds http URL when app.url is http', function () {
    config(['app.url' => 'http://hmo.test']);

    expect(TenantUrl::to('acme', 'admin'))->toBe('http://acme.hmo.test/admin');
});

test('TenantUrl::to builds https URL when app.url is https', function () {
    config(['app.url' => 'https://hmo.test']);

    expect(TenantUrl::to('acme', 'admin'))->toBe('https://acme.hmo.test/admin');
});

test('TenantUrl::to with no path returns base URL only', function () {
    config(['app.url' => 'https://hmo.test']);

    expect(TenantUrl::to('acme'))->toBe('https://acme.hmo.test');
});

test('TenantUrl::central builds http URL when app.url is http', function () {
    config(['app.url' => 'http://hmo.test']);

    expect(TenantUrl::central('landlord'))->toBe('http://hmo.test/landlord');
});

test('TenantUrl::central builds https URL when app.url is https', function () {
    config(['app.url' => 'https://hmo.test']);

    expect(TenantUrl::central('landlord'))->toBe('https://hmo.test/landlord');
});

test('TenantUrl::central with no path returns base URL only', function () {
    config(['app.url' => 'https://hmo.test']);

    expect(TenantUrl::central())->toBe('https://hmo.test');
});
