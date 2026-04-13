<?php

namespace App\Filament\Resources\SupplierInvoices\Schemas;

use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SupplierInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('supplier_invoice_number')
                            ->label("Supplier's Invoice Number")
                            ->required()
                            ->maxLength(100),
                        TextInput::make('internal_number')
                            ->label('Internal Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save'),
                        Select::make('partner_id')
                            ->label('Supplier')
                            ->options(
                                Partner::suppliers()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get): bool => ! empty($get('purchase_order_id')))
                            ->dehydrated(),
                        Select::make('purchase_order_id')
                            ->label('Purchase Order (optional)')
                            ->options(
                                PurchaseOrder::whereIn('status', ['confirmed', 'partially_received', 'received'])
                                    ->with('partner')
                                    ->get()
                                    ->mapWithKeys(fn (PurchaseOrder $po) => [
                                        $po->id => "{$po->po_number} — {$po->partner->name}",
                                    ])
                            )
                            ->searchable()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $po = PurchaseOrder::find($state);
                                    if ($po) {
                                        $set('partner_id', $po->partner_id);
                                        $set('currency_code', $po->currency_code);
                                        $set('exchange_rate', $po->exchange_rate);
                                        $set('pricing_mode', $po->pricing_mode->value);
                                    }
                                }
                            }),
                    ]),

                Section::make('Pricing & Currency')
                    ->columns(2)
                    ->schema([
                        Select::make('pricing_mode')
                            ->options(PricingMode::class)
                            ->required()
                            ->default(PricingMode::VatExclusive->value)
                            ->disabled(fn (Get $get): bool => ! empty($get('purchase_order_id')))
                            ->dehydrated(),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(Currency::active()->orderBy('name')->pluck('name', 'code'))
                            ->searchable()
                            ->required()
                            ->default('EUR')
                            ->disabled(fn (Get $get): bool => ! empty($get('purchase_order_id')))
                            ->dehydrated(),
                        TextInput::make('exchange_rate')
                            ->label('Exchange Rate')
                            ->required()
                            ->numeric()
                            ->default('1.000000')
                            ->step('0.000001'),
                    ]),

                Section::make('Dates & Payment')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('issued_at')
                            ->required()
                            ->default(now()->toDateString()),
                        DatePicker::make('received_at')
                            ->nullable(),
                        DatePicker::make('due_date')
                            ->nullable(),
                        Select::make('payment_method')
                            ->options(PaymentMethod::class)
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
