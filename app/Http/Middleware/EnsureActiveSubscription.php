<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenancy()->tenant;

        if ($tenant === null) {
            return $next($request);
        }

        if (! $tenant->isSubscriptionAccessible()) {
            // Allow logout and the subscription-expired page through
            if ($request->routeIs('filament.admin.auth.logout', 'subscription.expired')) {
                return $next($request);
            }

            return redirect()->route('subscription.expired');
        }

        return $next($request);
    }
}
