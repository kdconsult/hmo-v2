<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\CompanySettings;
use App\Models\Unit;
use App\Models\VatRate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('type')
                            ->options(ProductType::class)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ProductType|string|null $state) {
                                $value = $state instanceof ProductType ? $state->value : $state;
                                $set('is_stockable', $value !== ProductType::Service->value);
                            }),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->columnSpanFull(),
                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn () => Category::active()->get()->pluck('name', 'id'))
                            ->searchable()
                            ->required(fn () => (bool) CompanySettings::get('catalog', 'require_product_category', false)),
                        Select::make('unit_id')
                            ->label('Unit')
                            ->options(fn () => Unit::active()->get()->pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                    ]),

                Section::make('Pricing & Tax')
                    ->columns(2)
                    ->schema([
                        TextInput::make('purchase_price')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->nullable(),
                        TextInput::make('sale_price')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->nullable(),
                        Select::make('vat_rate_id')
                            ->label('VAT Rate')
                            ->options(VatRate::query()->active()->pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                    ]),

                Section::make('Settings')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_stockable')
                            ->default(true)
                            ->reactive(),
                        Select::make('status')
                            ->options(ProductStatus::class)
                            ->default(ProductStatus::Active)
                            ->required(),
                        TextInput::make('barcode')
                            ->maxLength(128)
                            ->nullable(),
                    ]),
            ]);
    }
}
