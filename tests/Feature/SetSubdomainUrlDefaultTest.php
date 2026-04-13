<?php

declare(strict_types=1);

use App\Http\Middleware\SetSubdomainUrlDefault;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['app.domain' => 'hmo.test']);
});

test('it sets URL defaults from route subdomain parameter', function () {
    $loginRouteName = 'tenant.login.'.Str::random(8);
    $probeRouteName = 'tenant.probe.'.Str::random(8);

    Route::domain('{subdomain}.hmo.test')
        ->get('/admin/login', fn () => 'login')
        ->name($loginRouteName);

    Route::middleware(SetSubdomainUrlDefault::class)
        ->domain('{subdomain}.hmo.test')
        ->get('/__probe-route-param', fn () => route($loginRouteName))
        ->name($probeRouteName);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $response = $this->get('http://bogui.hmo.test/__probe-route-param');

    $response->assertOk();
    $response->assertSee('http://bogui.hmo.test/admin/login', false);
});

test('it falls back to host subdomain when route parameter is unavailable', function () {
    $loginRouteName = 'tenant.login.'.Str::random(8);
    $probeRouteName = 'tenant.probe.'.Str::random(8);

    Route::domain('{subdomain}.hmo.test')
        ->get('/admin/login', fn () => 'login')
        ->name($loginRouteName);

    Route::middleware(SetSubdomainUrlDefault::class)
        ->get('/__probe-host-fallback', fn () => route($loginRouteName))
        ->name($probeRouteName);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $response = $this->get('http://bogui.hmo.test/__probe-host-fallback');

    $response->assertOk();
    $response->assertSee('http://bogui.hmo.test/admin/login', false);
});
