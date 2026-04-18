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
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Models\VatLegalReference;
use App\Services\CurrencyRateService;
use App\Support\TenantVatStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CustomerInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('EU OSS Threshold Warning')
                    ->description(function (): string {
                        $year = (int) now()->year;
                        $total = (float) EuOssAccumulation::where('year', $year)->sum('accumulated_amount_eur');

                        return 'Current EU B2C accumulation: €'.number_format($total, 2).' / €10,000';
                    })
                    ->status(function (): string {
                        $year = (int) now()->year;
                        $total = (float) EuOssAccumulation::where('year', $year)->sum('accumulated_amount_eur');

                        return $total >= 10000.0 ? 'danger' : 'warning';
                    })
                    ->visible(function (): bool {
                        $year = (int) now()->year;
                        $total = (float) EuOssAccumulation::where('year', $year)->sum('accumulated_amount_eur');

                        return $total >= 8000.0;
                    })
                    ->columnSpanFull(),

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

                                try {
                                    $scenario = VatScenario::determine(
                                        $partner,
                                        $tenantCountry,
                                        tenantIsVatRegistered: $tenantIsVatRegistered,
                                    );
                                } catch (\DomainException) {
                                    // Partner has no country_code — form helper text surfaces the fix
                                    return;
                                }

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

                                if (! TenantVatStatus::isRegistered()) {
                                    return __('invoice-form.exempt_non_registered_tenant');
                                }

                                $tenantCountry = CompanySettings::get('company', 'country_code');
                                if (! $tenantCountry) {
                                    return null;
                                }

                                $tenantIsVatRegistered = true;

                                try {
                                    $description = VatScenario::determine(
                                        $partner,
                                        $tenantCountry,
                                        tenantIsVatRegistered: $tenantIsVatRegistered,
                                    )->description();
                                } catch (\DomainException) {
                                    return '⚠ Partner has no country set. Edit the partner record to fix.';
                                }

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
                            ->helperText('Determined automatically at confirmation based on VIES result.')
                            ->visible(function (Get $get): bool {
                                if (! TenantVatStatus::isRegistered()) {
                                    return false;
                                }

                                $partner = Partner::find($get('partner_id'));
                                $tenantCountry = CompanySettings::get('company', 'country_code');

                                return ! ($partner && $tenantCountry && $partner->country_code === $tenantCountry);
                            }),
                        Toggle::make('is_domestic_exempt')
                            ->label(__('invoice-form.domestic_exempt_toggle'))
                            ->helperText(__('invoice-form.domestic_exempt_hint'))
                            ->live()
                            ->dehydrated(false)
                            ->visible(function (Get $get): bool {
                                if (! TenantVatStatus::isRegistered()) {
                                    return false;
                                }

                                $partner = Partner::find($get('partner_id'));
                                $tenantCountry = CompanySettings::get('company', 'country_code');

                                return $partner && $partner->country_code === $tenantCountry;
                            })
                            ->afterStateHydrated(function ($component, ?Model $record): void {
                                if ($record?->vat_scenario === VatScenario::DomesticExempt) {
                                    $component->state(true);
                                }
                            })
                            ->afterStateUpdated(function (bool $state, callable $set): void {
                                if (! $state) {
                                    $set('vat_scenario', null);
                                    $set('vat_scenario_sub_code', null);

                                    return;
                                }

                                $set('vat_scenario', VatScenario::DomesticExempt->value);

                                $country = CompanySettings::get('company', 'country_code');
                                $default = VatLegalReference::forCountry($country)
                                    ->ofScenario('domestic_exempt')
                                    ->default()
                                    ->first();

                                if ($default) {
                                    $set('vat_scenario_sub_code', $default->sub_code);
                                }
                            }),
                        Select::make('vat_scenario_sub_code')
                            ->label(__('invoice-form.exemption_article'))
                            ->options(function (): array {
                                $country = CompanySettings::get('company', 'country_code');

                                return VatLegalReference::listForScenario($country, 'domestic_exempt')
                                    ->mapWithKeys(fn ($ref) => [
                                        $ref->sub_code => "{$ref->legal_reference} — {$ref->getTranslation('description', app()->getLocale(), false)}",
                                    ])
                                    ->toArray();
                            })
                            ->visible(fn (Get $get): bool => (bool) $get('is_domestic_exempt'))
                            ->required(fn (Get $get): bool => (bool) $get('is_domestic_exempt')),
                    ]),

                Section::make('Pricing & Currency')
                    ->columns(2)
                    ->schema([
                        Select::make('pricing_mode')
                            ->options(PricingMode::class)
                            ->required()
                            ->default(PricingMode::VatExclusive->value)
                            ->visible(fn () => TenantVatStatus::isRegistered())
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
                                try {
                                    $scenario = VatScenario::determine(
                                        $partner,
                                        $tenantCountry,
                                        tenantIsVatRegistered: $tenantIsVatRegistered,
                                    );
                                } catch (\DomainException) {
                                    return false;
                                }

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
                        DatePicker::make('supplied_at')
                            ->label(__('invoice-form.date_of_supply'))
                            ->helperText(__('invoice-form.date_of_supply_hint'))
                            ->nullable()
                            ->default(fn (Get $get) => $get('issued_at')),
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
