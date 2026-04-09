<?php

namespace App\Filament\Resources\Currencies\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(3)
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : $state)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('symbol')
                    ->maxLength(10),
                TextInput::make('decimal_places')
                    ->numeric()
                    ->integer()
                    ->default(2)
                    ->minValue(0)
                    ->maxValue(8),
                Toggle::make('is_default')
                    ->label('Default Currency'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
