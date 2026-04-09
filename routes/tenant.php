<?php

declare(strict_types=1);

use App\Http\Controllers\StripeCheckoutController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::domain('{subdomain}.'.config('app.domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
    ])->group(function () {
        Route::get('/', function () {
            return 'This is your multi-tenant application. The id of the current tenant is '.tenant('id');
        });

        Route::middleware('auth')->group(function () {
            Route::post('/checkout', [StripeCheckoutController::class, 'createCheckoutSession'])->name('checkout.create');
            Route::get('/checkout/success', [StripeCheckoutController::class, 'checkoutSuccess'])->name('checkout.success');
        });
    });
