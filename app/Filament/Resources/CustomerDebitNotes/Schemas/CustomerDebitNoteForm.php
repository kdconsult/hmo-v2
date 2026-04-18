<?php

namespace App\Filament\Resources\CustomerDebitNotes\Schemas;

use App\Enums\DebitNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\Currency;
use App\Models\CustomerInvoice;
use App\Services\CurrencyRateService;
use App\Support\TenantVatStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CustomerDebitNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Debit Note')
                    ->columns(2)
                    ->schema([
                        TextInput::make('debit_note_number')
                            ->label('Debit Note Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('customer_invoice_id')
                            ->label('Customer Invoice (optional)')
                            ->options(
                                CustomerInvoice::whereIn('status', [
                                    DocumentStatus::Confirmed->value,
                                    DocumentStatus::Paid->value,
                                ])
                                    ->with('partner')
                                    ->get()
                                    ->mapWithKeys(fn (CustomerInvoice $inv) => [
                                        $inv->id => "{$inv->invoice_number} — {$inv->partner->name}",
                                    ])
                            )
                            ->searchable()
                            ->nullable()
                            ->live()
                            ->helperText(fn (Get $get): ?string => CustomerInvoice::find($get('customer_invoice_id'))?->vat_scenario?->description()
                                ? __('invoice-form.vat_treatment_inherited_hint')
                                : null)
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $invoice = CustomerInvoice::find($state);
                                    if ($invoice) {
                                        $set('partner_id', $invoice->partner_id);
                                        $set('currency_code', $invoice->currency_code);
                                        $set('exchange_rate', $invoice->exchange_rate);
                                        $set('pricing_mode', $invoice->pricing_mode->value);
                                    }
                                }
                            }),
                        Select::make('reason')
                            ->options(DebitNoteReason::class)
                            ->required()
                            ->default(DebitNoteReason::AdditionalCharge->value)
                            ->live(),
                        Textarea::make('reason_description')
                            ->label('Reason Details')
                            ->rows(2)
                            ->visible(fn (Get $get): bool => $get('reason') === DebitNoteReason::Other->value)
                            ->columnSpanFull(),
                        DatePicker::make('issued_at')
                            ->required()
                            ->default(now()->toDateString())
                            ->live(onBlur: true)
                            ->afterStateUpdated(CurrencyRateService::makeAfterDateChanged()),
                        DatePicker::make('triggering_event_date')
                            ->label(__('invoice-form.triggering_event_date'))
                            ->helperText(__('invoice-form.triggering_event_date_hint'))
                            ->nullable()
                            ->default(now()->toDateString()),
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
                        Select::make('pricing_mode')
                            ->options(PricingMode::class)
                            ->required()
                            ->default(PricingMode::VatExclusive->value)
                            ->visible(fn (): bool => TenantVatStatus::isRegistered()),
                        TextInput::make('partner_id')
                            ->hidden()
                            ->dehydrated(),
                    ]),
            ]);
    }
}
