<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Plans\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),

                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100)
                            ->alphaDash(),

                        TextInput::make('price')
                            ->required()
                            ->numeric()
                            ->prefix('EUR')
                            ->default(0)
                            ->minValue(0),

                        Select::make('billing_period')
                            ->options([
                                'monthly' => 'Monthly',
                                'yearly' => 'Yearly',
                                'lifetime' => 'Lifetime',
                            ])
                            ->placeholder('Free (no billing)'),

                        TextInput::make('max_users')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Unlimited'),

                        TextInput::make('max_documents')
                            ->label('Max Documents / Month')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Unlimited'),

                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->required(),

                        Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                    ]),

                Section::make('Features')
                    ->schema([
                        KeyValue::make('features')
                            ->keyLabel('Feature key')
                            ->valueLabel('Value')
                            ->addActionLabel('Add feature'),
                    ]),
            ]);
    }
}
