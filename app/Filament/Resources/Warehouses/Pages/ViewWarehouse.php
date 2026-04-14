<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\ProductVariant;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\StockService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

class ViewWarehouse extends ViewRecord
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            Action::make('view_stock')
                ->label('View Stock')
                ->icon(Heroicon::OutlinedCube)
                ->url(fn (Warehouse $record): string => StockItemResource::getUrl('index').'?'.Arr::query([
                    'tableFilters' => ['warehouse' => ['value' => $record->id]],
                ])),

            Action::make('view_movements')
                ->label('View Movements')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(fn (Warehouse $record): string => StockMovementResource::getUrl('index').'?'.Arr::query([
                    'tableFilters' => ['warehouse' => ['value' => $record->id]],
                ])),

            Action::make('transfer_stock')
                ->label('Transfer Stock')
                ->icon(Heroicon::OutlinedTruck)
                ->color('warning')
                ->schema([
                    Select::make('product_variant_id')
                        ->label('Product / Variant')
                        ->options(fn (Warehouse $record): array => StockItem::where('warehouse_id', $record->id)
                            ->where('quantity', '>', 0)
                            ->with('productVariant.product')
                            ->get()
                            ->mapWithKeys(fn (StockItem $si): array => [
                                $si->product_variant_id => $si->productVariant->is_default
                                    ? "{$si->productVariant->sku} — {$si->productVariant->product->name} (qty: {$si->quantity})"
                                    : "{$si->productVariant->sku} — {$si->productVariant->product->name} / {$si->productVariant->name} (qty: {$si->quantity})",
                            ])
                            ->all()
                        )
                        ->required()
                        ->searchable(),
                    TextInput::make('quantity')
                        ->required()
                        ->numeric()
                        ->minValue(0.0001)
                        ->step(0.0001),
                    Select::make('to_warehouse_id')
                        ->label('Destination Warehouse')
                        ->options(fn (Warehouse $record): array => Warehouse::active()->where('id', '!=', $record->id)->pluck('name', 'id')->all()
                        )
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data, Warehouse $record): void {
                    try {
                        app(StockService::class)->transfer(
                            variant: ProductVariant::findOrFail($data['product_variant_id']),
                            fromWarehouse: $record,
                            toWarehouse: Warehouse::findOrFail($data['to_warehouse_id']),
                            quantity: $data['quantity'],
                        );

                        Notification::make()->title('Stock transferred successfully')->success()->send();
                    } catch (InsufficientStockException $e) {
                        Notification::make()->title('Insufficient stock')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
