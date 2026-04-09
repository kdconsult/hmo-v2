<?php

namespace App\Filament\Landlord\Resources\Tenants\Tables;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Mail\ProformaInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->badge()
                    ->suffix(fn (Tenant $record): string => $record->isLandlordTenant() ? ' ★' : ''),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('eik')
                    ->label('EIK')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->badge(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('subscription_status')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('deactivated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deletion_scheduled_for')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')
                    ->options(TenantStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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
                    ->action(function (Tenant $record, array $data): void {
                        $record->suspend(auth()->user(), $data['reason']);
                    })
                    ->visible(fn (Tenant $record): bool => $record->isActive())
                    ->authorize(fn (Tenant $record): bool => auth()->user()->can('suspend', $record)),
                Action::make('markForDeletion')
                    ->label('Mark for Deletion')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Mark Tenant for Deletion')
                    ->modalDescription('This will mark the tenant as pending deletion. They will receive a warning email. You can still reactivate them during the grace period.')
                    ->action(function (Tenant $record): void {
                        $record->markForDeletion();
                    })
                    ->visible(fn (Tenant $record): bool => $record->isSuspended())
                    ->authorize(fn (Tenant $record): bool => auth()->user()->can('markForDeletion', $record)),
                Action::make('scheduleForDeletion')
                    ->label('Schedule for Deletion')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Schedule Tenant for Deletion')
                    ->modalDescription('The tenant database will be permanently deleted on the date below. This cannot be undone after the automated job runs.')
                    ->schema([
                        DateTimePicker::make('deletion_scheduled_for')
                            ->label('Delete on')
                            ->default(now()->addDays(30))
                            ->required()
                            ->minDate(now()->addDay()),
                    ])
                    ->action(function (Tenant $record, array $data): void {
                        $record->scheduleForDeletion(Carbon::parse($data['deletion_scheduled_for']));
                    })
                    ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::MarkedForDeletion)
                    ->authorize(fn (Tenant $record): bool => auth()->user()->can('scheduleForDeletion', $record)),
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Reactivate Tenant')
                    ->modalDescription('This will restore the tenant to active status and clear all deactivation data.')
                    ->action(function (Tenant $record): void {
                        $record->reactivate();
                    })
                    ->visible(fn (Tenant $record): bool => ! $record->isActive())
                    ->authorize(fn (Tenant $record): bool => auth()->user()->can('reactivate', $record)),

                Action::make('changePlan')
                    ->label('Change Plan')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->modalHeading('Change Subscription Plan')
                    ->schema([
                        Select::make('plan_id')
                            ->label('New Plan')
                            ->options(Plan::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                            ->default(fn (Tenant $record) => $record->plan_id)
                            ->required(),
                    ])
                    ->action(function (Tenant $record, array $data): void {
                        $plan = Plan::findOrFail($data['plan_id']);
                        app(SubscriptionService::class)->changePlan($record, $plan);
                        Notification::make()->success()->title('Plan changed to '.$plan->name.'.')->send();
                    })
                    ->hidden(fn (Tenant $record): bool => $record->isLandlordTenant()),

                Action::make('cancelSubscription')
                    ->label('Cancel Subscription')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel Subscription')
                    ->modalDescription(fn (Tenant $record): string => 'The tenant will retain access until '.($record->subscription_ends_at?->format('d M Y') ?? 'the end of their current period').'.')
                    ->action(function (Tenant $record): void {
                        app(SubscriptionService::class)->cancelSubscription($record);
                        Notification::make()->warning()->title('Subscription cancelled.')->send();
                    })
                    ->visible(fn (Tenant $record): bool => $record->subscription_status === SubscriptionStatus::Active)
                    ->hidden(fn (Tenant $record): bool => $record->isLandlordTenant()),

                Action::make('recordPayment')
                    ->label('Record Payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading('Record Bank Transfer Payment')
                    ->schema([
                        Select::make('plan_id')
                            ->label('Plan')
                            ->options(Plan::where('is_active', true)->pluck('name', 'id'))
                            ->default(fn (Tenant $record) => $record->plan_id)
                            ->required(),
                        TextInput::make('amount')
                            ->label('Amount (EUR)')
                            ->numeric()
                            ->default(fn (Tenant $record) => $record->plan?->price)
                            ->required(),
                        TextInput::make('bank_transfer_reference')
                            ->label('Bank Transfer Reference')
                            ->required(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2),
                    ])
                    ->action(function (Tenant $record, array $data): void {
                        $plan = Plan::findOrFail($data['plan_id']);

                        $payment = Payment::create([
                            'tenant_id' => $record->id,
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

                        app(SubscriptionService::class)->recordPaymentAndActivate($record, $plan, $payment);

                        Notification::make()->success()->title('Payment recorded and subscription activated.')->send();
                    })
                    ->hidden(fn (Tenant $record): bool => $record->isLandlordTenant()),

                Action::make('sendProformaInvoice')
                    ->label('Send Proforma Invoice')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Send Proforma Invoice')
                    ->modalDescription('This will send a proforma invoice PDF to the tenant owner\'s email address.')
                    ->action(function (Tenant $record): void {
                        $plan = $record->plan;
                        if (! $plan) {
                            Notification::make()->danger()->title('Tenant has no plan assigned.')->send();

                            return;
                        }

                        $ownerUser = $record->users()->first();
                        if (! $ownerUser) {
                            Notification::make()->danger()->title('No user found for this tenant.')->send();

                            return;
                        }

                        $tenantUser = User::find($ownerUser->id);
                        Mail::to($record->email)->send(new ProformaInvoice($record, $tenantUser, $plan));

                        Notification::make()->success()->title('Proforma invoice sent to '.$record->email)->send();
                    })
                    ->hidden(fn (Tenant $record): bool => $record->isLandlordTenant()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
