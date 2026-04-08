<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Mail\TrialExpired;
use App\Mail\TrialExpiringSoon;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('app:check-trial-expirations')]
#[Description('Mark expired trials as past_due and send warning emails for trials expiring soon.')]
class CheckTrialExpirations extends Command
{
    public function handle(): int
    {
        // Warn tenants whose trial expires in 3 days
        Tenant::where('subscription_status', SubscriptionStatus::Trial->value)
            ->whereBetween('trial_ends_at', [now()->addDays(2)->startOfDay(), now()->addDays(3)->endOfDay()])
            ->each(function (Tenant $tenant) {
                $owner = $tenant->users()->first();
                if ($owner) {
                    Mail::to($owner->email)->send(new TrialExpiringSoon($tenant, $owner));
                }
                $this->line("Warned: {$tenant->name}");
            });

        // Expire trials that have passed
        $expired = Tenant::where('subscription_status', SubscriptionStatus::Trial->value)
            ->where('trial_ends_at', '<=', now())
            ->get();

        foreach ($expired as $tenant) {
            $tenant->update(['subscription_status' => SubscriptionStatus::PastDue->value]);

            $owner = $tenant->users()->first();
            if ($owner) {
                Mail::to($owner->email)->send(new TrialExpired($tenant, $owner));
            }

            $this->line("Expired: {$tenant->name}");
        }

        $this->info("Processed {$expired->count()} expired trials.");

        return Command::SUCCESS;
    }
}
