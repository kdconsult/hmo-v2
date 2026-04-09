<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Payments\Tables;

use App\Enums\PaymentGateway;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('amount')
                    ->money('EUR')
                    ->sortable(),

                TextColumn::make('gateway')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('bank_transfer_reference')
                    ->label('Reference')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('paid_at')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('period_start')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('period_end')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('recordedBy.name')
                    ->label('Recorded by')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('paid_at', 'desc')
            ->filters([
                SelectFilter::make('gateway')
                    ->options(PaymentGateway::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
