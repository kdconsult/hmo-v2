<?php

namespace App\Filament\Resources\SupplierCreditNotes\Schemas;

use App\Enums\CreditNoteReason;
use App\Enums\PricingMode;
use App\Models\Currency;
use App\Models\SupplierInvoice;
use App\Services\CurrencyRateService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SupplierCreditNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Credit Note')
                    ->columns(2)
                    ->schema([
                        TextInput::make('credit_note_number')
                            ->label('Credit Note Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('supplier_invoice_id')
                            ->label('Supplier Invoice')
                            ->options(
                                SupplierInvoice::whereIn('status', ['confirmed', 'paid'])
                                    ->with('partner')
                                    ->get()
                                    ->mapWithKeys(fn (SupplierInvoice $inv) => [
                                        $inv->id => "{$inv->internal_number} — {$inv->partner->name}",
                                    ])
                            )
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $invoice = SupplierInvoice::find($state);
                                    if ($invoice) {
                                        $set('partner_id', $invoice->partner_id);
                                        $set('currency_code', $invoice->currency_code);
                                        $set('exchange_rate', $invoice->exchange_rate);
                                        $set('pricing_mode', $invoice->pricing_mode->value);
                                    }
                                }
                            }),
                        Select::make('reason')
                            ->options(CreditNoteReason::class)
                            ->required()
                            ->default(CreditNoteReason::Return->value)
                            ->live(),
                        Textarea::make('reason_description')
                            ->label('Reason Details')
                            ->rows(2)
                            ->visible(fn (Get $get): bool => $get('reason') === CreditNoteReason::Other->value)
                            ->columnSpanFull(),
                        DatePicker::make('issued_at')
                            ->required()
                            ->default(now()->toDateString())
                            ->live(onBlur: true)
                            ->afterStateUpdated(CurrencyRateService::makeAfterDateChanged()),
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
                            ->helperText('Auto-filled from exchange rate table when available.'),
                        Select::make('pricing_mode')
                            ->options(PricingMode::class)
                            ->required()
                            ->default(PricingMode::VatExclusive->value),
                        TextInput::make('partner_id')
                            ->hidden()
                            ->dehydrated(),
                    ]),
            ]);
    }
}
