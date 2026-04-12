<?php

namespace App\Filament\Resources\GoodsReceivedNotes\RelationManagers;

use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
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

class GoodsReceivedNoteItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Received Items';

    public function isReadOnly(): bool
    {
        /** @var GoodsReceivedNote $grn */
        $grn = $this->getOwnerRecord();

        return ! $grn->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var GoodsReceivedNote $grn */
        $grn = $this->getOwnerRecord();

        $poItemOptions = [];
        if ($grn->purchase_order_id) {
            $poItemOptions = PurchaseOrderItem::where('purchase_order_id', $grn->purchase_order_id)
                ->with('productVariant')
                ->get()
                ->filter(fn (PurchaseOrderItem $item) => bccomp($item->remainingQuantity(), '0', 4) > 0)
                ->mapWithKeys(fn (PurchaseOrderItem $item) => [
                    $item->id => $item->productVariant
                        ? "{$item->productVariant->sku} (remaining: {$item->remainingQuantity()})"
                        : "Item #{$item->id} (remaining: {$item->remainingQuantity()})",
                ])
                ->toArray();
        }

        return $schema
            ->columns(2)
            ->components([
                Select::make('purchase_order_item_id')
                    ->label('PO Line Item')
                    ->options($poItemOptions)
                    ->searchable()
                    ->nullable()
                    ->visible(fn (): bool => ! empty($poItemOptions))
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state) {
                            $poItem = PurchaseOrderItem::with('productVariant')->find($state);
                            if ($poItem) {
                                $set('product_variant_id', $poItem->product_variant_id);
                                $set('quantity', $poItem->remainingQuantity());
                                $set('unit_cost', $poItem->unit_price);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Select::make('product_variant_id')
                    ->label('Product')
                    ->options(
                        ProductVariant::with('product')
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $v) => [
                                $v->id => "{$v->sku} — {$v->product->name}",
                            ])
                    )
                    ->searchable()
                    ->required()
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
                Action::make('import_from_po')
                    ->label('Import from PO')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(function (): bool {
                        /** @var GoodsReceivedNote $grn */
                        $grn = $this->getOwnerRecord();

                        return $grn->purchase_order_id !== null && $grn->isEditable();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Import items from Purchase Order')
                    ->modalDescription('This will add all remaining PO items as GRN items. You can edit individual quantities afterward for partial receipts.')
                    ->action(function (): void {
                        /** @var GoodsReceivedNote $grn */
                        $grn = $this->getOwnerRecord();

                        $poItems = PurchaseOrderItem::where('purchase_order_id', $grn->purchase_order_id)
                            ->with('productVariant')
                            ->get()
                            ->filter(fn (PurchaseOrderItem $item) => bccomp($item->remainingQuantity(), '0', 4) > 0);

                        if ($poItems->isEmpty()) {
                            Notification::make()
                                ->title('No remaining items to import')
                                ->warning()
                                ->send();

                            return;
                        }

                        foreach ($poItems as $poItem) {
                            GoodsReceivedNoteItem::create([
                                'goods_received_note_id' => $grn->id,
                                'purchase_order_item_id' => $poItem->id,
                                'product_variant_id' => $poItem->product_variant_id,
                                'quantity' => $poItem->remainingQuantity(),
                                'unit_cost' => $poItem->unit_price,
                            ]);
                        }

                        Notification::make()
                            ->title('Items imported from PO')
                            ->success()
                            ->send();
                    }),
                CreateAction::make(),
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
