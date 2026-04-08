<?php

namespace App\Filament\Landlord\Resources\Tenants\Tables;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                    ->badge(),
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
                TextColumn::make('subscription_plan')
                    ->badge()
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
                        $record->scheduleForDeletion($data['deletion_scheduled_for']);
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
