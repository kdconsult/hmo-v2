<?php

namespace App\Filament\Resources\AdvancePayments\Schemas;

use App\Enums\PaymentMethod;
use App\Enums\SalesOrderStatus;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Services\CurrencyRateService;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AdvancePaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Advance Payment')
                    ->columns(2)
                    ->schema([
                        TextInput::make('ap_number')
                            ->label('AP Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('sales_order_id')
                            ->label('Sales Order (optional)')
                            ->options(
                                SalesOrder::whereNotIn('status', [SalesOrderStatus::Cancelled->value, SalesOrderStatus::Invoiced->value])
                                    ->with('partner')
                                    ->get()
                                    ->mapWithKeys(fn (SalesOrder $so) => [
                                        $so->id => "{$so->so_number} — {$so->partner->name}",
                                    ])
                            )
                            ->searchable()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $so = SalesOrder::find($state);
                                    if ($so) {
                                        $set('partner_id', $so->partner_id);
                                        $set('currency_code', $so->currency_code);
                                        $set('exchange_rate', $so->exchange_rate);
                                    }
                                }
                            }),
                        Select::make('partner_id')
                            ->label('Customer')
                            ->options(
                                Partner::customers()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get): bool => ! empty($get('sales_order_id')))
                            ->dehydrated(),
                        TextInput::make('amount')
                            ->label('Amount')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step('0.01')
                            ->placeholder('0.00')
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $soId = $get('sales_order_id');
                                    if (! $soId) {
                                        return;
                                    }
                                    $so = SalesOrder::find($soId);
                                    if (! $so) {
                                        return;
                                    }
                                    $remaining = $so->remainingBalance();
                                    if (bccomp((string) $value, $remaining, 2) > 0) {
                                        $fail("Amount cannot exceed the SO remaining balance of {$remaining}.");
                                    }
                                },
                            ]),
                        Select::make('payment_method')
                            ->label('Payment Method')
                            ->options(PaymentMethod::class)
                            ->required(),
                        DatePicker::make('received_at')
                            ->label('Received Date')
                            ->required()
                            ->default(now()->toDateString())
                            ->live(onBlur: true)
                            ->afterStateUpdated(CurrencyRateService::makeAfterDateChanged()),
                    ]),

                Section::make('Currency')
                    ->columns(2)
                    ->schema([
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(Currency::active()->orderBy('name')->pluck('name', 'code'))
                            ->searchable()
                            ->required()
                            ->default('EUR')
                            ->disabled(fn (Get $get): bool => ! empty($get('sales_order_id')))
                            ->dehydrated()
                            ->live()
                            ->afterStateUpdated(CurrencyRateService::makeAfterCurrencyChanged('received_at')),
                        TextInput::make('exchange_rate')
                            ->label('Exchange Rate')
                            ->required()
                            ->numeric()
                            ->default('1.000000')
                            ->step('0.000001')
                            ->placeholder('Enter rate…')
                            ->helperText('Auto-filled when a saved rate exists. Enter manually and click the bookmark to save.')
                            ->suffixAction(CurrencyRateService::makeSaveRateAction('received_at')),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
