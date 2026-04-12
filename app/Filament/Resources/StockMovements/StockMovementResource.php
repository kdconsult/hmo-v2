<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\MovementType;
use App\Enums\NavigationGroup;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Warehouse;

    protected static ?string $navigationLabel = 'Stock Movements';

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
                TextColumn::make('moved_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('productVariant.product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->numeric(4)
                    ->color(fn (StockMovement $record): string => (float) $record->quantity >= 0 ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('notes')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(MovementType::class),
                SelectFilter::make('warehouse')
                    ->relationship('warehouse', 'name')
                    ->options(Warehouse::query()->active()->pluck('name', 'id')),
                Filter::make('moved_at')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($q, $from) => $q->whereDate('moved_at', '>=', $from))
                        ->when($data['until'], fn ($q, $until) => $q->whereDate('moved_at', '<=', $until))
                    ),
            ])
            ->defaultSort('moved_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
