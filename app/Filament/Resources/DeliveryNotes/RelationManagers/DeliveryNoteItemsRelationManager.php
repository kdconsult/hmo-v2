<?php

namespace App\Filament\Resources\DeliveryNotes\RelationManagers;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeliveryNoteItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Delivery Items';

    public function isReadOnly(): bool
    {
        /** @var DeliveryNote $dn */
        $dn = $this->getOwnerRecord();

        return ! $dn->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var DeliveryNote $dn */
        $dn = $this->getOwnerRecord();

        $soItemOptions = [];
        if ($dn->sales_order_id) {
            $soItemOptions = SalesOrderItem::where('sales_order_id', $dn->sales_order_id)
                ->with('productVariant')
                ->get()
                ->filter(fn (SalesOrderItem $item) => bccomp($item->remainingDeliverableQuantity(), '0', 4) > 0)
                ->mapWithKeys(fn (SalesOrderItem $item) => [
                    $item->id => $item->productVariant
                        ? "{$item->productVariant->sku} (remaining: {$item->remainingDeliverableQuantity()})"
                        : "Item #{$item->id} (remaining: {$item->remainingDeliverableQuantity()})",
                ])
                ->toArray();
        }

        return $schema
            ->columns(2)
            ->components([
                Select::make('sales_order_item_id')
                    ->label('SO Line Item')
                    ->options($soItemOptions)
                    ->searchable()
                    ->nullable()
                    ->visible(fn (): bool => ! empty($soItemOptions))
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state) {
                            $soItem = SalesOrderItem::with('productVariant')->find($state);
                            if ($soItem) {
                                $set('product_variant_id', $soItem->product_variant_id);
                                $set('quantity', $soItem->remainingDeliverableQuantity());
                                $set('unit_cost', $soItem->unit_price);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Select::make('product_variant_id')
                    ->label('Product')
                    ->options(ProductVariant::variantOptionsForSelect())
                    ->searchable()
                    ->required(fn (): bool => empty($soItemOptions))
                    ->visible(fn (): bool => empty($soItemOptions))
                    ->dehydrated()
                    ->columnSpanFull(),

                TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->minValue(0.0001)
                    ->step('0.0001')
                    ->default('1.0000'),

                TextInput::make('unit_cost')
                    ->label('Unit Cost')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step('0.0001')
                    ->default('0.0000'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('productVariant.product.name')
                    ->label('Product'),
                TextColumn::make('quantity')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->numeric(4),
            ])
            ->headerActions([
                Action::make('import_from_so')
                    ->label('Import from SO')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(function (): bool {
                        /** @var DeliveryNote $dn */
                        $dn = $this->getOwnerRecord();

                        return $dn->sales_order_id !== null && $dn->isEditable();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Import items from Sales Order')
                    ->modalDescription('This will add all remaining SO items as delivery note items. You can edit individual quantities afterward for partial deliveries.')
                    ->action(function (): void {
                        /** @var DeliveryNote $dn */
                        $dn = $this->getOwnerRecord();

                        $soItems = SalesOrderItem::where('sales_order_id', $dn->sales_order_id)
                            ->with('productVariant')
                            ->get()
                            ->filter(fn (SalesOrderItem $item) => bccomp($item->remainingDeliverableQuantity(), '0', 4) > 0);

                        if ($soItems->isEmpty()) {
                            Notification::make()
                                ->title('No remaining items to import')
                                ->warning()
                                ->send();

                            return;
                        }

                        foreach ($soItems as $soItem) {
                            DeliveryNoteItem::create([
                                'delivery_note_id' => $dn->id,
                                'sales_order_item_id' => $soItem->id,
                                'product_variant_id' => $soItem->product_variant_id,
                                'quantity' => $soItem->remainingDeliverableQuantity(),
                                'unit_cost' => $soItem->unit_price,
                            ]);
                        }

                        Notification::make()
                            ->title('Items imported from SO')
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (empty($data['product_variant_id']) && ! empty($data['sales_order_item_id'])) {
                            $soItem = SalesOrderItem::find($data['sales_order_item_id']);
                            if ($soItem) {
                                $data['product_variant_id'] = $soItem->product_variant_id;
                            }
                        }

                        return $data;
                    }),
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
}
