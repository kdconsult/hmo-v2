<?php

namespace App\Filament\Resources\Quotations\Tables;

use App\Enums\QuotationStatus;
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

class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quotation_number')
                    ->label('Quotation #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('partner.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Lines')
                    ->sortable(),
                TextColumn::make('total')
                    ->money(fn ($record) => $record->currency_code ?? 'EUR')
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->date()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('partner_id')
                    ->label('Partner')
                    ->relationship('partner', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('issued_date')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('issued_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('issued_at', '<=', $date));
                    }),
                SelectFilter::make('status')
                    ->options(QuotationStatus::class),
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
                                if ($record->status === QuotationStatus::Draft) {
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
