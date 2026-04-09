<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Plan;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

class SubscriptionExpiredPage extends Page
{
    protected string $view = 'filament.pages.subscription-expired-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationCircle;

    protected static bool $shouldRegisterNavigation = false;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'subscription-expired';
    }

    public function getTitle(): string
    {
        return 'Subscription Expired';
    }

    #[Computed]
    public function paidPlans(): Collection
    {
        return Plan::where('is_active', true)
            ->whereNotNull('billing_period')
            ->orderBy('sort_order')
            ->get();
    }

    public function upgradeNow(int $planId): void
    {
        $tenant = tenancy()->tenant;
        $plan = Plan::findOrFail($planId);
        $appDomain = config('app.domain');

        $checkout = $tenant->checkoutCharge(
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

        $this->redirect($checkout->url, navigate: false);
    }
}
