<?php

namespace App\Filament\Resources\SalesOrders\Schemas;

use App\Enums\PricingMode;
use App\Enums\QuotationStatus;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\Quotation;
use App\Models\Warehouse;
use App\Services\CurrencyRateService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SalesOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sales Order')
                    ->columns(2)
                    ->schema([
                        TextInput::make('so_number')
                            ->label('SO Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('partner_id')
                            ->label('Customer')
                            ->options(
                                Partner::customers()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabled(fn (Get $get) => filled($get('quotation_id')))
                            ->dehydrated(),
                        Select::make('quotation_id')
                            ->label('Linked Quotation')
                            ->options(fn (Get $get): array => $get('partner_id')
                                ? Quotation::where('partner_id', $get('partner_id'))
                                    ->whereIn('status', [QuotationStatus::Accepted->value, QuotationStatus::Sent->value])
                                    ->orderByDesc('issued_at')
                                    ->pluck('quotation_number', 'id')
                                    ->all()
                                : []
                            )
                            ->searchable()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $quotation = Quotation::find($state);
                                    if ($quotation) {
                                        $set('currency_code', $quotation->currency_code);
                                        $set('exchange_rate', $quotation->exchange_rate);
                                        $set('pricing_mode', $quotation->pricing_mode->value);
                                    }
                                }
                            }),
                        Select::make('warehouse_id')
                            ->label('Warehouse')
                            ->options(Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
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
                            ->afterStateUpdated(CurrencyRateService::makeAfterCurrencyChanged('issued_at')),
                        TextInput::make('exchange_rate')
                            ->label('Exchange Rate')
                            ->required()
                            ->numeric()
                            ->default('1.000000')
                            ->step('0.000001')
                            ->placeholder('Enter rate…')
                            ->helperText('Auto-filled when a saved rate exists. Enter manually and click the bookmark to save.')
                            ->suffixAction(CurrencyRateService::makeSaveRateAction('issued_at')),
                    ]),

                Section::make('Dates')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('issued_at')
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
