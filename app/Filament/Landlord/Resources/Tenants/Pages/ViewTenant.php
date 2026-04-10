<?php

namespace App\Filament\Landlord\Resources\Tenants\Pages;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Filament\Landlord\Resources\Tenants\TenantResource;
use App\Mail\ProformaInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Mail;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            // --- Lifecycle ---
            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Suspend Tenant')
                ->modalDescription('Suspending this tenant will cut off their access. You can reactivate them at any time.')
                ->schema([
                    Select::make('reason')
                        ->label('Reason')
                        ->options([
                            'non_payment' => 'Non-payment',
                            'tenant_request' => 'Tenant request',
                            'other' => 'Other',
                        ])
                        ->default('non_payment')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->record->suspend(auth()->user(), $data['reason']);
                    $this->refreshFormData(['status', 'deactivated_at', 'deactivated_by', 'deactivation_reason']);
                })
                ->visible(fn (): bool => $this->record->isActive() && ! $this->record->isLandlordTenant())
                ->authorize(fn (): bool => auth()->user()->can('suspend', $this->record)),

            Action::make('markForDeletion')
                ->label('Mark for Deletion')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Mark Tenant for Deletion')
                ->modalDescription('This will mark the tenant as pending deletion. They will receive a warning email.')
                ->action(function (): void {
                    $this->record->markForDeletion();
                    $this->refreshFormData(['status', 'marked_for_deletion_at']);
                })
                ->visible(fn (): bool => $this->record->isSuspended())
                ->authorize(fn (): bool => auth()->user()->can('markForDeletion', $this->record)),

            Action::make('scheduleForDeletion')
                ->label('Schedule for Deletion')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Schedule Tenant for Deletion')
                ->modalDescription('The tenant database will be permanently deleted on the scheduled date. This cannot be undone.')
                ->schema([
                    DateTimePicker::make('deletion_scheduled_for')
                        ->label('Delete on')
                        ->default(now()->addDays(30))
                        ->required()
                        ->minDate(now()->addDay()),
                ])
                ->action(function (array $data): void {
                    $this->record->scheduleForDeletion(Carbon::parse($data['deletion_scheduled_for']));
                    $this->refreshFormData(['status', 'scheduled_for_deletion_at', 'deletion_scheduled_for']);
                })
                ->visible(fn (): bool => $this->record->status === TenantStatus::MarkedForDeletion)
                ->authorize(fn (): bool => auth()->user()->can('scheduleForDeletion', $this->record)),

            Action::make('reactivate')
                ->label('Reactivate')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Reactivate Tenant')
                ->modalDescription('This will restore the tenant to active status and clear all deactivation data.')
                ->action(function (): void {
                    $this->record->reactivate();
                    $this->refreshFormData([
                        'status',
                        'deactivated_at',
                        'deactivated_by',
                        'deactivation_reason',
                        'marked_for_deletion_at',
                        'scheduled_for_deletion_at',
                        'deletion_scheduled_for',
                    ]);
                })
                ->visible(fn (): bool => ! $this->record->isActive() && ! $this->record->isLandlordTenant())
                ->authorize(fn (): bool => auth()->user()->can('reactivate', $this->record)),

            // --- Billing ---
            Action::make('changePlan')
                ->label('Change Plan')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->modalHeading('Change Subscription Plan')
                ->schema([
                    Select::make('plan_id')
                        ->label('New Plan')
                        ->options(Plan::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                        ->default(fn (): mixed => $this->record->plan_id)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $plan = Plan::findOrFail($data['plan_id']);
                    app(SubscriptionService::class)->changePlan($this->record, $plan);
                    Notification::make()->success()->title('Plan changed to '.$plan->name.'.')->send();
                    $this->refreshFormData(['plan_id', 'subscription_status']);
                })
                ->visible(fn (): bool => ! $this->record->isLandlordTenant())
                ->authorize(fn (): bool => auth()->user()->can('changePlan', $this->record)),

            Action::make('cancelSubscription')
                ->label('Cancel Subscription')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancel Subscription')
                ->modalDescription(fn (): string => 'The tenant will retain access until '.($this->record->subscription_ends_at?->format('d M Y') ?? 'the end of their current period').'.')
                ->action(function (): void {
                    app(SubscriptionService::class)->cancelSubscription($this->record);
                    Notification::make()->warning()->title('Subscription cancelled.')->send();
                    $this->refreshFormData(['subscription_status', 'subscription_ends_at']);
                })
                ->visible(fn (): bool => $this->record->subscription_status === SubscriptionStatus::Active && ! $this->record->isLandlordTenant())
                ->authorize(fn (): bool => auth()->user()->can('cancelSubscription', $this->record)),

            Action::make('recordPayment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->modalHeading('Record Bank Transfer Payment')
                ->schema([
                    Select::make('plan_id')
                        ->label('Plan')
                        ->options(Plan::where('is_active', true)->pluck('name', 'id'))
                        ->default(fn (): mixed => $this->record->plan_id)
                        ->required(),
                    TextInput::make('amount')
                        ->label('Amount (EUR)')
                        ->numeric()
                        ->default(fn (): mixed => $this->record->plan?->price)
                        ->required(),
                    TextInput::make('bank_transfer_reference')
                        ->label('Bank Transfer Reference')
                        ->maxLength(255)
                        ->required(),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->maxLength(1000)
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    $plan = Plan::findOrFail($data['plan_id']);

                    $payment = Payment::create([
                        'tenant_id' => $this->record->id,
                        'plan_id' => $plan->id,
                        'amount' => $data['amount'],
                        'currency' => 'EUR',
                        'gateway' => PaymentGateway::BankTransfer,
                        'status' => PaymentStatus::Pending,
                        'bank_transfer_reference' => $data['bank_transfer_reference'],
                        'notes' => $data['notes'] ?? null,
                        'period_start' => now()->toDateString(),
                        'period_end' => match ($plan->billing_period) {
                            'monthly' => now()->addMonth()->toDateString(),
                            'yearly' => now()->addYear()->toDateString(),
                            default => null,
                        },
                        'recorded_by' => auth()->id(),
                    ]);

                    app(SubscriptionService::class)->recordPaymentAndActivate($this->record, $plan, $payment);
                    Notification::make()->success()->title('Payment recorded and subscription activated.')->send();
                    $this->refreshFormData(['plan_id', 'subscription_status', 'subscription_ends_at']);
                })
                ->visible(fn (): bool => ! $this->record->isLandlordTenant() && $this->record->plan !== null && ! $this->record->plan->isFree())
                ->authorize(fn (): bool => auth()->user()->can('recordPayment', $this->record)),

            Action::make('sendProformaInvoice')
                ->label('Send Proforma Invoice')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Send Proforma Invoice')
                ->modalDescription(fn (): string => 'This will send a proforma invoice PDF to '.($this->record->email ?? 'the tenant').'.')
                ->action(function (): void {
                    $plan = $this->record->plan;

                    if (! $plan) {
                        Notification::make()->danger()->title('Tenant has no plan assigned.')->send();

                        return;
                    }

                    $ownerUser = $this->record->users()->first();

                    if (! $ownerUser) {
                        Notification::make()->danger()->title('No user found for this tenant.')->send();

                        return;
                    }

                    $tenantUser = User::find($ownerUser->id);
                    Mail::to($this->record->email)->send(new ProformaInvoice($this->record, $tenantUser, $plan));
                    Notification::make()->success()->title('Proforma invoice sent to '.$this->record->email.'.')->send();
                })
                ->visible(fn (): bool => ! $this->record->isLandlordTenant() && $this->record->plan !== null && ! $this->record->plan->isFree())
                ->authorize(fn (): bool => auth()->user()->can('sendProformaInvoice', $this->record)),
        ];
    }
}
