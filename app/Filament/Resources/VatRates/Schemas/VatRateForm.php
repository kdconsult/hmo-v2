<?php

namespace App\Filament\Resources\VatRates\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VatRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('country_code')
                    ->label('Country Code')
                    ->required()
                    ->maxLength(2)
                    ->upperCase()
                    ->default('BG'),
                Select::make('type')
                    ->options([
                        'standard' => 'Standard',
                        'reduced' => 'Reduced',
                        'zero' => 'Zero',
                        'exempt' => 'Exempt',
                    ])
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->columnSpanFull(),
                TextInput::make('rate')
                    ->numeric()
                    ->required()
                    ->suffix('%')
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('sort_order')
                    ->numeric()
                    ->integer()
                    ->default(0),
                DatePicker::make('effective_from')
                    ->label('Effective From'),
                DatePicker::make('effective_to')
                    ->label('Effective To'),
                Toggle::make('is_default')
                    ->label('Default Rate'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
