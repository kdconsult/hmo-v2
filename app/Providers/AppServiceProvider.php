<?php

namespace App\Providers;

use App\Models\Contract;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Polymorphic morph map - prevents class name leakage in DB
        Relation::morphMap([
            'partner' => Partner::class,
            'contract' => Contract::class,
            // Phase 3+ models will be added here as implemented
        ]);

        // Super-admin bypasses all gates — only on the tenant panel (tenancy initialized).
        // On the landlord panel (central context), policies decide without bypass.
        Gate::before(function ($user, $ability) {
            if (tenancy()->initialized && method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
                return true;
            }
        });
    }
}
