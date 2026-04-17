<?php

namespace App\Filament\Resources\Partners\Schemas;

use App\Enums\PartnerType;
use App\Enums\PaymentMethod;
use App\Enums\VatStatus;
use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\VatRate;
use App\Support\Countries;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->columns(1)
                    ->schema([
                        Section::make('General Info')
                            ->columns(2)
                            ->schema([
                                Select::make('type')
                                    ->options(PartnerType::class)
                                    ->required()
                                    ->default(PartnerType::Company->value),
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('company_name')
                                    ->maxLength(255),
                                TextInput::make('eik')
                                    ->label('EIK')
                                    ->maxLength(20),
                                TextInput::make('mol')
                                    ->label('MOL')
                                    ->maxLength(255),
                                Select::make('country_code')
                                    ->label('Country')
                                    ->options(Countries::forSelect())
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->default(fn () => CompanySettings::get('company', 'country_code')
                                        ?? tenancy()->tenant?->country_code)
                                    ->helperText('Determines VAT treatment on invoices. Required.')
                                    ->afterStateUpdated(fn ($livewire) => $livewire->resetVatState()),
                                TextInput::make('email')
                                    ->email()
                                    ->maxLength(255),
                                TextInput::make('phone')
                                    ->tel()
                                    ->maxLength(50),
                                TextInput::make('secondary_phone')
                                    ->tel()
                                    ->maxLength(50),
                                TextInput::make('website')
                                    ->url()
                                    ->maxLength(255),
                            ]),

                        Section::make('Classification')
                            ->columns(2)
                            ->schema([
                                Toggle::make('is_customer')
                                    ->label('Customer')
                                    ->default(true),
                                Toggle::make('is_supplier')
                                    ->label('Supplier'),
                                Toggle::make('is_active')
                                    ->default(true),
                            ]),
                    ]),

                Grid::make()
                    ->columns(1)
                    ->schema([
                        Section::make('VAT Registration')
                            ->schema([
                                Hidden::make('vat_status')
                                    ->default(VatStatus::NotRegistered->value),

                                Toggle::make('is_vat_registered')
                                    ->label('Partner is VAT Registered')
                                    ->live()
                                    ->inline(false)
                                    ->afterStateUpdated(function (bool $state, $livewire): void {
                                        if (! $state) {
                                            $livewire->resetVatState();
                                        }
                                    }),

                                TextInput::make('vat_lookup')
                                    ->label('VAT Number (without country prefix)')
                                    ->prefix(fn ($livewire): string => $livewire->vatCountryPrefix())
                                    ->visible(fn (Get $get): bool => (bool) $get('is_vat_registered'))
                                    ->helperText(fn ($livewire): ?string => $livewire->vatLookupHelperText())
                                    ->dehydrated(false)
                                    ->suffixAction(
                                        Action::make('check_vies')
                                            ->label('Check VIES')
                                            ->icon(Heroicon::Bolt)
                                            ->action(fn ($livewire) => $livewire->handleViesCheck())
                                    ),

                                TextInput::make('vat_number')
                                    ->label('Confirmed VAT Number')
                                    ->disabled()
                                    ->dehydrated()
                                    ->visible(fn (Get $get): bool => filled($get('vat_number'))),
                            ]),

                        Section::make('Financial')
                            ->columns(2)
                            ->schema([
                                Select::make('default_currency_code')
                                    ->label('Currency')
                                    ->options(Currency::active()->orderBy('name')->pluck('name', 'code'))
                                    ->searchable()
                                    ->default('EUR'),
                                TextInput::make('default_payment_term_days')
                                    ->label('Payment Terms (days)')
                                    ->numeric()
                                    ->integer()
                                    ->default(30),
                                Select::make('default_payment_method')
                                    ->options(PaymentMethod::class),
                                Select::make('default_vat_rate_id')
                                    ->label('Default VAT Rate')
                                    ->options(VatRate::active()->pluck('name', 'id'))
                                    ->searchable(),
                                TextInput::make('credit_limit')
                                    ->numeric()
                                    ->prefix('EUR'),
                                TextInput::make('discount_percent')
                                    ->numeric()
                                    ->suffix('%'),
                            ]),
                    ]),

            ]);
    }
}
