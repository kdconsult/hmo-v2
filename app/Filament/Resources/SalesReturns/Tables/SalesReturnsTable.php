<?php

namespace App\Filament\Resources\SalesReturns\Tables;

use App\Enums\SalesReturnStatus;
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

class SalesReturnsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sr_number')
                    ->label('SR Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('partner.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Lines')
                    ->sortable(),
                TextColumn::make('returned_at')
                    ->label('Return Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('deliveryNote.dn_number')
                    ->label('Delivery Note')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('partner_id')
                    ->label('Partner')
                    ->relationship('partner', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('returned_date')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('returned_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('returned_at', '<=', $date));
                    }),
                SelectFilter::make('status')
                    ->options(SalesReturnStatus::class),
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
                                if ($record->status === SalesReturnStatus::Draft) {
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
