<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->placeholder('—'),
                TextColumn::make('sale_price')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('vatRate.name')
                    ->label('VAT Rate')
                    ->placeholder('—'),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->state(fn (Product $record): string => number_format(
                        (float) ($record->defaultVariant?->stockItems()->sum('quantity') ?? 0),
                        4
                    ))
                    ->badge()
                    ->color(fn (string $state): string => (float) $state > 0 ? 'success' : 'gray'),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(ProductType::class),
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn () => Category::active()->get()->pluck('name', 'id')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                RestoreAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}
