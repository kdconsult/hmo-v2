<?php

namespace App\Filament\Resources\Partners\Schemas;

use App\Enums\PartnerType;
use App\Enums\PaymentMethod;
use App\Models\Currency;
use App\Models\VatRate;
use App\Support\EuCountries;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Info')
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->options(PartnerType::class)
                            ->required()
                            ->default(PartnerType::Company->value),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('company_name')
                            ->maxLength(255),
                        TextInput::make('eik')
                            ->label('EIK')
                            ->maxLength(20),
                        TextInput::make('vat_number')
                            ->label('VAT Number')
                            ->maxLength(20),
                        TextInput::make('mol')
                            ->label('MOL')
                            ->maxLength(255),
                        Select::make('country_code')
                            ->label('Country')
                            ->options(EuCountries::forSelect())
                            ->searchable()
                            ->helperText('Determines EU VAT treatment on invoices.'),
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

                Grid::make()
                    ->columns(1)
                    ->schema([

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
