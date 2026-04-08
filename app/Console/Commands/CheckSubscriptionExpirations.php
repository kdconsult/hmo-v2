<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-subscription-expirations')]
#[Description('Mark paid subscriptions as past_due when their subscription_ends_at has passed.')]
class CheckSubscriptionExpirations extends Command
{
    public function handle(): int
    {
        $expired = Tenant::where('subscription_status', SubscriptionStatus::Active->value)
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<=', now())
            ->get();

        foreach ($expired as $tenant) {
            $tenant->update(['subscription_status' => SubscriptionStatus::PastDue->value]);
            $this->line("Past due: {$tenant->name}");
        }

        $this->info("Processed {$expired->count()} expired subscriptions.");

        return Command::SUCCESS;
    }
}
