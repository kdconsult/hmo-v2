<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductType;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
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
                TextColumn::make('status')
                    ->badge()
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
                ReplicateAction::make()
                    ->excludeAttributes(['barcode'])
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['code'] = ($data['code'] ?? '').'-COPY';

                        return $data;
                    })
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->unique(table: 'products', ignoreRecord: true),
                    ])
                    ->after(function (Product $replica, Product $record): void {
                        $record->variants()
                            ->where('is_default', false)
                            ->get()
                            ->each(function ($variant) use ($replica): void {
                                $clone = $variant->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                                $clone->product_id = $replica->id;
                                $clone->sku = ($variant->sku ?? '').'-COPY';
                                $clone->save();
                            });
                    })
                    ->successRedirectUrl(fn (Product $replica): string => ProductResource::getUrl('edit', ['record' => $replica])),
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
