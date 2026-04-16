<?php

namespace App\Filament\Resources\CustomerInvoices\Schemas;

use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\SalesOrderStatus;
use App\Enums\VatScenario;
use App\Enums\VatStatus;
use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Services\CurrencyRateService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CustomerInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('invoice_number')
                            ->label('Invoice Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save'),
                        Select::make('invoice_type')
                            ->options(InvoiceType::class)
                            ->required()
                            ->default(InvoiceType::Standard->value)
                            ->disabled(fn (Get $get) => filled($get('sales_order_id')))
                            ->dehydrated(),
                        Select::make('partner_id')
                            ->label('Customer')
                            ->options(
                                Partner::customers()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabled(fn (Get $get): bool => ! empty($get('sales_order_id')))
                            ->dehydrated()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (! $state) {
                                    return;
                                }

                                $partner = Partner::find($state);
                                if (! $partner) {
                                    return;
                                }

                                $tenantCountry = CompanySettings::get('company', 'country_code');
                                $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;

                                if (! $tenantCountry) {
                                    return;
                                }

                                $scenario = VatScenario::determine(
                                    $partner,
                                    $tenantCountry,
                                    tenantIsVatRegistered: $tenantIsVatRegistered,
                                );

                                // Force VAT-exclusive pricing for any non-domestic scenario
                                if ($scenario !== VatScenario::Domestic) {
                                    $set('pricing_mode', PricingMode::VatExclusive->value);
                                }

                                // Reflect expected reverse charge state
                                $set('is_reverse_charge', $scenario === VatScenario::EuB2bReverseCharge);
                            })
                            ->helperText(function (Get $get): ?string {
                                $partnerId = $get('partner_id');
                                if (! $partnerId) {
                                    return null;
                                }

                                $partner = Partner::find($partnerId);
                                if (! $partner) {
                                    return null;
                                }

                                $tenantCountry = CompanySettings::get('company', 'country_code');
                                if (! $tenantCountry) {
                                    return null;
                                }

                                $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;

                                $description = VatScenario::determine(
                                    $partner,
                                    $tenantCountry,
                                    tenantIsVatRegistered: $tenantIsVatRegistered,
                                )->description();

                                if ($partner->vat_status === VatStatus::Pending) {
                                    $description .= ' — VAT status pending, will be verified at confirmation.';
                                }

                                return $description;
                            }),
                        Select::make('sales_order_id')
                            ->label('Sales Order (optional)')
                            ->options(
                                SalesOrder::whereIn('status', [
                                    SalesOrderStatus::Confirmed->value,
                                    SalesOrderStatus::PartiallyDelivered->value,
                                    SalesOrderStatus::Delivered->value,
                                ])
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
                                        $set('pricing_mode', $so->pricing_mode->value);
                                    }
                                }
                            }),
                        Toggle::make('is_reverse_charge')
                            ->label('Reverse Charge (EU B2B)')
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull()
                            ->helperText('Determined automatically at confirmation based on VIES result.'),
                    ]),

                Section::make('Pricing & Currency')
                    ->columns(2)
                    ->schema([
                        Select::make('pricing_mode')
                            ->options(PricingMode::class)
                            ->required()
                            ->default(PricingMode::VatExclusive->value)
                            ->disabled(function (Get $get): bool {
                                // Locked when linked to a SO
                                if (! empty($get('sales_order_id'))) {
                                    return true;
                                }

                                // Forced to VAT-exclusive for non-domestic VAT scenarios
                                $partnerId = $get('partner_id');
                                if (! $partnerId) {
                                    return false;
                                }

                                $partner = Partner::find($partnerId);
                                if (! $partner) {
                                    return false;
                                }

                                $tenantCountry = CompanySettings::get('company', 'country_code');
                                if (! $tenantCountry) {
                                    return false;
                                }

                                $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;
                                $scenario = VatScenario::determine(
                                    $partner,
                                    $tenantCountry,
                                    tenantIsVatRegistered: $tenantIsVatRegistered,
                                );

                                return $scenario !== VatScenario::Domestic;
                            })
                            ->dehydrated(),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(Currency::active()->orderBy('name')->pluck('name', 'code'))
                            ->searchable()
                            ->required()
                            ->default('EUR')
                            ->disabled(fn (Get $get): bool => ! empty($get('sales_order_id')))
                            ->dehydrated()
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

                Section::make('Dates & Payment')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('issued_at')
                            ->required()
                            ->default(now()->toDateString())
                            ->live(onBlur: true)
                            ->afterStateUpdated(CurrencyRateService::makeAfterDateChanged()),
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
