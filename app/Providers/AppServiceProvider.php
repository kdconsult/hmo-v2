<?php

namespace App\Providers;

use App\Listeners\StripeWebhookListener;
use App\Models\Contract;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookReceived;

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

        // Explicit Stripe webhook listener registration — ensures the listener is bound
        // regardless of Laravel's auto-discovery state.
        Event::listen(WebhookReceived::class, StripeWebhookListener::class);

        // Super-admin bypasses all gates — only on the tenant panel (tenancy initialized).
        // On the landlord panel (central context), policies decide without bypass.
        Gate::before(function ($user, $ability) {
            if (tenancy()->initialized && method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
                return true;
            }
        });
    }
}
