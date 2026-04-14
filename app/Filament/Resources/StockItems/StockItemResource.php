<?php

namespace App\Filament\Resources\StockItems;

use App\Enums\NavigationGroup;
use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockItems\Pages\ListStockItems;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\StockService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

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
                    ->sortable()
                    ->url(fn (StockItem $record): string => ProductResource::getUrl('view', ['record' => $record->productVariant->product_id])),
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
                    ->sortable()
                    ->url(fn (StockItem $record): string => WarehouseResource::getUrl('view', ['record' => $record->warehouse_id])),
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
            ->recordActions([
                Action::make('view_movements')
                    ->label('Movements')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (StockItem $record): string => StockMovementResource::getUrl('index').'?'.Arr::query([
                        'tableFilters' => ['warehouse' => ['value' => $record->warehouse_id]],
                    ])),
                Action::make('transfer')
                    ->label('Transfer')
                    ->icon(Heroicon::OutlinedTruck)
                    ->color('warning')
                    ->schema([
                        Select::make('to_warehouse_id')
                            ->label('Destination Warehouse')
                            ->options(fn (StockItem $record): array => Warehouse::active()->where('id', '!=', $record->warehouse_id)->pluck('name', 'id')->all())
                            ->required()
                            ->searchable(),
                        TextInput::make('quantity')
                            ->required()
                            ->numeric()
                            ->minValue(0.0001)
                            ->step(0.0001),
                    ])
                    ->action(function (array $data, StockItem $record): void {
                        try {
                            app(StockService::class)->transfer(
                                variant: $record->productVariant,
                                fromWarehouse: $record->warehouse,
                                toWarehouse: Warehouse::findOrFail($data['to_warehouse_id']),
                                quantity: $data['quantity'],
                            );

                            Notification::make()->title('Stock transferred successfully')->success()->send();
                        } catch (InsufficientStockException $e) {
                            Notification::make()->title('Insufficient stock')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['productVariant.product', 'warehouse', 'stockLocation']))
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockItems::route('/'),
        ];
    }
}
