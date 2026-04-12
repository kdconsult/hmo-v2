<?php

namespace App\Filament\Resources\StockItems;

use App\Enums\NavigationGroup;
use App\Filament\Resources\StockItems\Pages\ListStockItems;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockItemResource extends Resource
{
    protected static ?string $model = StockItem::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Warehouse;

    protected static ?string $navigationLabel = 'Stock Levels';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label('Variant')
                    ->placeholder('Default')
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->sortable(),
                TextColumn::make('stockLocation.name')
                    ->label('Location')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('reserved_quantity')
                    ->numeric(4)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('available_quantity')
                    ->label('Available')
                    ->state(fn (StockItem $record): float => $record->available_quantity)
                    ->numeric(4),
            ])
            ->filters([
                SelectFilter::make('warehouse')
                    ->relationship('warehouse', 'name')
                    ->options(Warehouse::query()->active()->pluck('name', 'id')),
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::active()->get()->pluck('name', 'id')->filter(fn ($name) => filled($name)))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn ($q, $value) => $q->whereHas('productVariant', fn ($q) => $q->where('product_id', $value))
                    )),
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn () => Category::active()->get()->pluck('name', 'id')->filter(fn ($name) => filled($name)))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn ($q, $value) => $q->whereHas('productVariant.product', fn ($q) => $q->where('category_id', $value))
                    )),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockItems::route('/'),
        ];
    }
}
