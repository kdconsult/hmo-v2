<?php

namespace App\Filament\Resources\AdvancePayments\Tables;

use App\Enums\AdvancePaymentStatus;
use App\Models\AdvancePayment;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdvancePaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ap_number')
                    ->label('AP Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('partner.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn ($record) => $record->currency_code ?? 'EUR')
                    ->sortable(),
                TextColumn::make('amount_applied')
                    ->label('Applied')
                    ->money(fn ($record) => $record->currency_code ?? 'EUR')
                    ->sortable(),
                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->state(fn (AdvancePayment $record): string => $record->remainingAmount())
                    ->money(fn ($record) => $record->currency_code ?? 'EUR'),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('partner_id')
                    ->label('Partner')
                    ->relationship('partner', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('received_date')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('received_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('received_at', '<=', $date));
                    }),
                SelectFilter::make('status')
                    ->options(AdvancePaymentStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn ($record) => $record->isEditable()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->each(function ($record): void {
                                if ($record->status === AdvancePaymentStatus::Open) {
                                    $record->delete();
                                }
                            });
                        }),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
