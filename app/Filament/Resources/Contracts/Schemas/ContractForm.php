<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Enums\ContractStatus;
use App\Enums\SeriesType;
use App\Models\NumberSeries;
use App\Models\Partner;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contract Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('contract_number')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->options(ContractStatus::class)
                            ->required()
                            ->default(ContractStatus::Active->value),
                        Select::make('partner_id')
                            ->label('Partner')
                            ->options(Partner::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Select::make('document_series_id')
                            ->label('Number Series')
                            ->options(
                                NumberSeries::where('is_active', true)
                                    ->where('series_type', SeriesType::Invoice->value)
                                    ->pluck('name', 'id')
                            )
                            ->searchable(),
                        Select::make('type')
                            ->options([
                                'maintenance' => 'Maintenance',
                                'sla' => 'SLA',
                                'subscription' => 'Subscription',
                            ])
                            ->required(),
                        TextInput::make('currency_code')
                            ->label('Currency')
                            ->default('EUR')
                            ->maxLength(3),
                    ]),

                Section::make('Terms')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('start_date')
                            ->required(),
                        DatePicker::make('end_date'),
                        Toggle::make('auto_renew')
                            ->label('Auto-renew'),
                        TextInput::make('monthly_fee')
                            ->numeric()
                            ->prefix('€'),
                        TextInput::make('included_hours')
                            ->numeric()
                            ->suffix('h'),
                        TextInput::make('billing_day')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(28)
                            ->default(1),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
