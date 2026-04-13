<?php

namespace App\Filament\Resources\PurchaseReturns\RelationManagers;

use App\Models\GoodsReceivedNoteItem;
use App\Models\ProductVariant;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class PurchaseReturnItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Return Items';

    public function isReadOnly(): bool
    {
        /** @var PurchaseReturn $pr */
        $pr = $this->getOwnerRecord();

        return ! $pr->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var PurchaseReturn $pr */
        $pr = $this->getOwnerRecord();

        $grnItemOptions = [];
        if ($pr->goods_received_note_id) {
            $grnItemOptions = GoodsReceivedNoteItem::where('goods_received_note_id', $pr->goods_received_note_id)
                ->with('productVariant')
                ->get()
                ->filter(fn (GoodsReceivedNoteItem $item) => bccomp($item->remainingReturnableQuantity(), '0', 4) > 0)
                ->mapWithKeys(fn (GoodsReceivedNoteItem $item) => [
                    $item->id => $item->productVariant
                        ? "{$item->productVariant->sku} (returnable: {$item->remainingReturnableQuantity()})"
                        : "Item #{$item->id} (returnable: {$item->remainingReturnableQuantity()})",
                ])
                ->toArray();
        }

        return $schema
            ->columns(2)
            ->components([
                Select::make('goods_received_note_item_id')
                    ->label('GRN Line Item')
                    ->options($grnItemOptions)
                    ->searchable()
                    ->nullable()
                    ->visible(fn (): bool => ! empty($grnItemOptions))
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state) {
                            $grnItem = GoodsReceivedNoteItem::with('productVariant')->find($state);
                            if ($grnItem) {
                                $set('product_variant_id', $grnItem->product_variant_id);
                                $set('quantity', $grnItem->remainingReturnableQuantity());
                                $set('unit_cost', $grnItem->unit_cost);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Select::make('product_variant_id')
                    ->label('Product')
                    ->options(ProductVariant::variantOptionsForSelect())
                    ->searchable()
                    ->required(fn (): bool => empty($grnItemOptions))
                    ->visible(fn (): bool => empty($grnItemOptions))
                    ->dehydrated()
                    ->columnSpanFull(),

                TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->minValue(0.0001)
                    ->step('0.0001')
                    ->default('1.0000')
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $grnItemId = $get('goods_received_note_item_id');
                            if (! $grnItemId) {
                                return;
                            }

                            DB::transaction(function () use ($grnItemId, $value, $fail): void {
                                $grnItem = GoodsReceivedNoteItem::lockForUpdate()->find($grnItemId);
                                if (! $grnItem) {
                                    return;
                                }

                                $remaining = $grnItem->remainingReturnableQuantity();
                                if (bccomp((string) $value, $remaining, 4) > 0) {
                                    $fail("Quantity exceeds remaining returnable quantity ({$remaining}).");
                                }
                            });
                        },
                    ]),

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
                Action::make('import_from_grn')
                    ->label('Import from GRN')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(function (): bool {
                        /** @var PurchaseReturn $pr */
                        $pr = $this->getOwnerRecord();

                        return $pr->goods_received_note_id !== null && $pr->isEditable();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Import items from Goods Receipt')
                    ->modalDescription('This will add all remaining returnable GRN items as return items. You can edit individual quantities afterward.')
                    ->action(function (): void {
                        /** @var PurchaseReturn $pr */
                        $pr = $this->getOwnerRecord();

                        $existingGrnItemIds = PurchaseReturnItem::where('purchase_return_id', $pr->id)
                            ->whereNotNull('goods_received_note_item_id')
                            ->pluck('goods_received_note_item_id')
                            ->toArray();

                        $grnItems = GoodsReceivedNoteItem::where('goods_received_note_id', $pr->goods_received_note_id)
                            ->with('productVariant')
                            ->get()
                            ->filter(fn (GoodsReceivedNoteItem $item) => bccomp($item->remainingReturnableQuantity(), '0', 4) > 0)
                            ->reject(fn (GoodsReceivedNoteItem $item) => in_array($item->id, $existingGrnItemIds));

                        if ($grnItems->isEmpty()) {
                            Notification::make()
                                ->title('No remaining items to import')
                                ->warning()
                                ->send();

                            return;
                        }

                        foreach ($grnItems as $grnItem) {
                            PurchaseReturnItem::create([
                                'purchase_return_id' => $pr->id,
                                'goods_received_note_item_id' => $grnItem->id,
                                'product_variant_id' => $grnItem->product_variant_id,
                                'quantity' => $grnItem->remainingReturnableQuantity(),
                                'unit_cost' => $grnItem->unit_cost,
                            ]);
                        }

                        Notification::make()
                            ->title('Items imported from GRN')
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (empty($data['product_variant_id']) && ! empty($data['goods_received_note_item_id'])) {
                            $grnItem = GoodsReceivedNoteItem::find($data['goods_received_note_item_id']);
                            if ($grnItem) {
                                $data['product_variant_id'] = $grnItem->product_variant_id;
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
