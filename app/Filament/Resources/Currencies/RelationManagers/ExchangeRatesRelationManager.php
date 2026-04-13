<?php

namespace App\Filament\Resources\Currencies\RelationManagers;

use App\Models\Currency;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExchangeRatesRelationManager extends RelationManager
{
    protected static string $relationship = 'exchangeRates';

    protected static ?string $recordTitleAttribute = 'date';

    public function form(Schema $schema): Schema
    {
        $baseCurrencyCode = Currency::where('is_default', true)->value('code') ?? 'EUR';

        return $schema
            ->components([
                DatePicker::make('date')
                    ->required()
                    ->default(now()->toDateString())
                    ->unique(
                        table: 'exchange_rates',
                        column: 'date',
                        modifyRuleUsing: fn ($rule) => $rule
                            ->where('currency_id', $this->getOwnerRecord()->id)
                            ->where('base_currency_code', $baseCurrencyCode),
                        ignoreRecord: true,
                    ),

                TextInput::make('rate')
                    ->required()
                    ->numeric()
                    ->step('0.000001')
                    ->minValue(0.000001),

                Hidden::make('base_currency_code')
                    ->default($baseCurrencyCode)
                    ->dehydrated(),

                Hidden::make('source')
                    ->default('manual')
                    ->dehydrated(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('rate')
                    ->numeric(decimalPlaces: 6)
                    ->sortable(),
                TextColumn::make('base_currency_code')
                    ->label('Base'),
                TextColumn::make('source')
                    ->badge(),
                TextColumn::make('created_at')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
