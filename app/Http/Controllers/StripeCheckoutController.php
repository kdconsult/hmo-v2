<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StripeCheckoutController extends Controller
{
    public function createCheckoutSession(Request $request): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id']]);
        $plan = Plan::findOrFail($data['plan_id']);
        $tenant = tenancy()->tenant;
        $appDomain = config('app.domain');

        return $tenant->checkoutCharge(
            (int) ($plan->price * 100),
            config('app.name').' — '.$plan->name.' Plan',
            1,
            [
                'success_url' => "http://{$tenant->slug}.{$appDomain}/checkout/success?session_id={CHECKOUT_SESSION_ID}",
                'cancel_url' => "http://{$tenant->slug}.{$appDomain}/admin/subscription-expired",
                'currency' => 'eur',
                'metadata' => ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
            ]
        );
    }

    public function checkoutSuccess(Request $request): View
    {
        return view('checkout.success', [
            'sessionId' => $request->query('session_id'),
        ]);
    }
}
