<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewTenantRegisteredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $appDomain = last(config('tenancy.central_domains'));
        $landlordUrl = "http://{$appDomain}/landlord/tenants/{$this->tenant->id}";

        return FilamentNotification::make()
            ->title('New tenant registered')
            ->body("{$this->tenant->name} ({$this->tenant->slug}) signed up for the {$this->tenant->plan?->name} plan.")
            ->icon('heroicon-o-building-office')
            ->iconColor('success')
            ->actions([
                Action::make('view')
                    ->label('View tenant')
                    ->url($landlordUrl),
            ])
            ->getDatabaseMessage();
    }
}
