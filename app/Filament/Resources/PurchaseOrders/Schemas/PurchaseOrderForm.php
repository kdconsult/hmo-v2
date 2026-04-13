<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Enums\PricingMode;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\Warehouse;
use App\Services\CurrencyRateService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Purchase Order')
                    ->columns(2)
                    ->schema([
                        TextInput::make('po_number')
                            ->label('PO Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('partner_id')
                            ->label('Supplier')
                            ->options(
                                Partner::suppliers()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required(),
                        Select::make('warehouse_id')
                            ->label('Destination Warehouse')
                            ->options(Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                    ]),

                Section::make('Pricing & Currency')
                    ->columns(2)
                    ->schema([
                        Select::make('pricing_mode')
                            ->options(PricingMode::class)
                            ->required()
                            ->default(PricingMode::VatExclusive->value),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(Currency::active()->orderBy('name')->pluck('name', 'code'))
                            ->searchable()
                            ->required()
                            ->default('EUR')
                            ->live()
                            ->afterStateUpdated(CurrencyRateService::makeAfterCurrencyChanged('ordered_at')),
                        TextInput::make('exchange_rate')
                            ->label('Exchange Rate')
                            ->required()
                            ->numeric()
                            ->default('1.000000')
                            ->step('0.000001')
                            ->helperText('Auto-filled from exchange rate table when available.'),
                    ]),

                Section::make('Dates')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('ordered_at')
                            ->required()
                            ->default(now()->toDateString())
                            ->live(onBlur: true)
                            ->afterStateUpdated(CurrencyRateService::makeAfterDateChanged()),
                        DatePicker::make('expected_delivery_date')
                            ->nullable(),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('internal_notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
