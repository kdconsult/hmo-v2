<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\PlanLimitService;
use App\Services\SubscriptionService;
use App\Support\TenantUrl;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

class SubscriptionPage extends Page
{
    protected string $view = 'filament.pages.subscription-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Subscription';

    protected static ?int $navigationSort = 99;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'subscription';
    }

    public function getTitle(): string
    {
        return 'Subscription & Billing';
    }

    #[Computed]
    public function tenant(): ?Tenant
    {
        return tenancy()->tenant;
    }

    #[Computed]
    public function usage(): array
    {
        return app(PlanLimitService::class)->getUsageSummary($this->tenant);
    }

    #[Computed]
    public function availablePlans(): Collection
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

        $checkout = $tenant->checkoutCharge(
            (int) ($plan->price * 100),
            config('app.name').' — '.$plan->name.' Plan',
            1,
            [
                'success_url' => TenantUrl::to($tenant->slug, 'checkout/success?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => TenantUrl::to($tenant->slug, 'admin/subscription'),
                'currency' => 'eur',
                'metadata' => ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
            ]
        );

        $this->redirect($checkout->url, navigate: false);
    }

    public function cancelSubscription(): void
    {
        if (! auth()->user()?->hasRole(['super-admin', 'admin'])) {
            Notification::make()
                ->danger()
                ->title('Unauthorized')
                ->body('Only administrators can cancel the subscription.')
                ->send();

            return;
        }

        $tenant = tenancy()->tenant;

        app(SubscriptionService::class)->cancelSubscription($tenant);

        Notification::make()
            ->warning()
            ->title('Subscription cancelled')
            ->body('Your subscription will remain active until the end of the current billing period.')
            ->send();
    }
}
