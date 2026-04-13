<?php

declare(strict_types=1);

use App\Http\Middleware\AdminPanelAuthenticate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['app.domain' => 'hmo.test']);
});

test('admin auth redirect includes subdomain from route parameter', function () {
    Route::domain('{subdomain}.hmo.test')
        ->get('/admin/login', fn () => 'login')
        ->name('filament.admin.auth.login');

    Route::domain('{subdomain}.hmo.test')
        ->get('/__auth-probe/{subdomain}', function () {
            $middleware = new class(app('auth')) extends AdminPanelAuthenticate
            {
                public function redirectForCurrentRequest(): ?string
                {
                    return $this->redirectTo(request());
                }
            };

            return $middleware->redirectForCurrentRequest();
        });

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $response = $this->get('http://bogui.hmo.test/__auth-probe/bogui');

    $response->assertOk();
    $response->assertSee('http://bogui.', false);
    $response->assertSee('/admin/login', false);
});

test('admin auth redirect falls back to host subdomain when route parameter is missing', function () {
    Route::domain('{subdomain}.hmo.test')
        ->get('/admin/login', fn () => 'login')
        ->name('filament.admin.auth.login');

    Route::get('/__auth-probe-host', function () {
        $middleware = new class(app('auth')) extends AdminPanelAuthenticate
        {
            public function redirectForCurrentRequest(): ?string
            {
                return $this->redirectTo(request());
            }
        };

        return $middleware->redirectForCurrentRequest();
    });

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $response = $this->get('http://bogui.hmo.test/__auth-probe-host');

    $response->assertOk();
    $response->assertSee('http://bogui.', false);
    $response->assertSee('/admin/login', false);
});
