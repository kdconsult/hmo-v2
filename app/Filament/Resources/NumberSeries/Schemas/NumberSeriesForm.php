<?php

namespace App\Filament\Resources\NumberSeries\Schemas;

use App\Enums\SeriesType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class NumberSeriesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Series Settings')
                    ->columns(2)
                    ->schema([
                        Select::make('series_type')
                            ->options(SeriesType::class)
                            ->required(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('prefix')
                            ->maxLength(20)
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? Str::upper($state) : null),
                        TextInput::make('separator')
                            ->default('-')
                            ->maxLength(5),
                    ]),

                Section::make('Number Format')
                    ->columns(2)
                    ->schema([
                        Toggle::make('include_year')
                            ->label('Include Year')
                            ->default(true),
                        Select::make('year_format')
                            ->label('Year Format')
                            ->options([
                                'Y' => '4 digits (2025)',
                                'y' => '2 digits (25)',
                            ])
                            ->default('Y'),
                        TextInput::make('padding')
                            ->numeric()
                            ->integer()
                            ->default(5)
                            ->minValue(1)
                            ->maxValue(10)
                            ->helperText('Number of digits (padded with zeros)'),
                        TextInput::make('next_number')
                            ->numeric()
                            ->integer()
                            ->default(1)
                            ->minValue(1),
                        Toggle::make('reset_yearly')
                            ->label('Reset Counter Yearly')
                            ->default(true),
                        Toggle::make('is_default')
                            ->label('Default for Type'),
                        Toggle::make('is_active')
                            ->default(true),
                    ]),
            ]);
    }
}
