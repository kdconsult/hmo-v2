<?php

namespace App\Filament\Resources\SalesOrders\RelationManagers;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\VatRate;
use App\Services\SalesOrderService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SalesOrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Line Items';

    public function isReadOnly(): bool
    {
        /** @var SalesOrder $order */
        $order = $this->getOwnerRecord();

        return ! $order->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('product_variant_id')
                    ->label('Product')
                    ->options(ProductVariant::variantOptionsForSelect())
                    ->searchable()
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        if ($state) {
                            $variant = ProductVariant::with('product')->find($state);
                            if ($variant) {
                                $set('unit_price', $variant->sale_price ?? '0.0000');
                                if (empty($get('description'))) {
                                    $set('description', $variant->getTranslation('name', app()->getLocale()) ?? '');
                                }
                                $product = $variant->product;
                                if ($product->vat_rate_id) {
                                    $set('vat_rate_id', $product->vat_rate_id);
                                }
                            }
                        }
                    })
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),

                TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->minValue(0.0001)
                    ->step('0.0001')
                    ->default('1.0000'),

                TextInput::make('unit_price')
                    ->label('Sale Price')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step('0.0001')
                    ->default('0.0000'),

                TextInput::make('discount_percent')
                    ->label('Discount %')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step('0.01')
                    ->default('0.00'),

                Select::make('vat_rate_id')
                    ->label('VAT Rate')
                    ->options(VatRate::active()->orderBy('rate')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                TextInput::make('sort_order')
                    ->numeric()
                    ->integer()
                    ->default(0)
                    ->hidden(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('description')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('discount_percent')
                    ->label('Disc%')
                    ->numeric(2)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vat_amount')
                    ->label('VAT')
                    ->numeric(2),
                TextColumn::make('line_total')
                    ->label('Net Total')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('qty_delivered')
                    ->label('Delivered')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('qty_invoiced')
                    ->label('Invoiced')
                    ->numeric(4)
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (SalesOrderItem $record): void {
                        $record->loadMissing(['salesOrder', 'vatRate']);
                        app(SalesOrderService::class)->recalculateItemTotals($record);
                        app(SalesOrderService::class)->recalculateDocumentTotals($record->salesOrder);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (SalesOrderItem $record): void {
                        $record->loadMissing(['salesOrder', 'vatRate']);
                        app(SalesOrderService::class)->recalculateItemTotals($record);
                        app(SalesOrderService::class)->recalculateDocumentTotals($record->salesOrder);
                    }),
                DeleteAction::make()
                    ->after(function (SalesOrderItem $record): void {
                        $order = SalesOrder::find($record->sales_order_id);
                        if ($order) {
                            app(SalesOrderService::class)->recalculateDocumentTotals($order);
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
