<?php

namespace App\Filament\Resources\Units;

use App\Enums\NavigationGroup;
use App\Enums\UnitType;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\Unit;
use App\Support\TranslatableLocales;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

class UnitResource extends Resource
{
    use Translatable;

    protected static ?string $model = Unit::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?string $navigationLabel = 'Units';

    public static function getTranslatableLocales(): array
    {
        return TranslatableLocales::forTenant();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('symbol')
                    ->required()
                    ->maxLength(20),
                Select::make('type')
                    ->options(UnitType::class)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('symbol')
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(UnitType::class),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnits::route('/'),
        ];
    }
}
