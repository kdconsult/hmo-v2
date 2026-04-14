<?php

namespace App\Filament\Resources\SalesReturns\RelationManagers;

use App\Models\DeliveryNoteItem;
use App\Models\ProductVariant;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
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

class SalesReturnItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Return Items';

    public function isReadOnly(): bool
    {
        /** @var SalesReturn $sr */
        $sr = $this->getOwnerRecord();

        return ! $sr->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var SalesReturn $sr */
        $sr = $this->getOwnerRecord();

        $dnItemOptions = [];
        if ($sr->delivery_note_id) {
            $dnItemOptions = DeliveryNoteItem::where('delivery_note_id', $sr->delivery_note_id)
                ->with('productVariant')
                ->get()
                ->filter(fn (DeliveryNoteItem $item) => bccomp($item->remainingReturnableQuantity(), '0', 4) > 0)
                ->mapWithKeys(fn (DeliveryNoteItem $item) => [
                    $item->id => $item->productVariant
                        ? "{$item->productVariant->sku} (returnable: {$item->remainingReturnableQuantity()})"
                        : "Item #{$item->id} (returnable: {$item->remainingReturnableQuantity()})",
                ])
                ->toArray();
        }

        return $schema
            ->columns(2)
            ->components([
                Select::make('delivery_note_item_id')
                    ->label('Delivery Note Line Item')
                    ->options($dnItemOptions)
                    ->searchable()
                    ->nullable()
                    ->visible(fn (): bool => ! empty($dnItemOptions))
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state) {
                            $dnItem = DeliveryNoteItem::with('productVariant')->find($state);
                            if ($dnItem) {
                                $set('product_variant_id', $dnItem->product_variant_id);
                                $set('quantity', $dnItem->remainingReturnableQuantity());
                                $set('unit_cost', $dnItem->unit_cost);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Select::make('product_variant_id')
                    ->label('Product')
                    ->options(ProductVariant::variantOptionsForSelect())
                    ->searchable()
                    ->required(fn (): bool => empty($dnItemOptions))
                    ->visible(fn (): bool => empty($dnItemOptions))
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
                            $dnItemId = $get('delivery_note_item_id');
                            if (! $dnItemId) {
                                return;
                            }

                            DB::transaction(function () use ($dnItemId, $value, $fail): void {
                                $dnItem = DeliveryNoteItem::lockForUpdate()->find($dnItemId);
                                if (! $dnItem) {
                                    return;
                                }

                                $remaining = $dnItem->remainingReturnableQuantity();
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
                Action::make('import_from_dn')
                    ->label('Import from Delivery Note')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(function (): bool {
                        /** @var SalesReturn $sr */
                        $sr = $this->getOwnerRecord();

                        return $sr->delivery_note_id !== null && $sr->isEditable();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Import items from Delivery Note')
                    ->modalDescription('This will add all remaining returnable delivery note items as return items. You can edit individual quantities afterward.')
                    ->action(function (): void {
                        /** @var SalesReturn $sr */
                        $sr = $this->getOwnerRecord();

                        $existingDnItemIds = SalesReturnItem::where('sales_return_id', $sr->id)
                            ->whereNotNull('delivery_note_item_id')
                            ->pluck('delivery_note_item_id')
                            ->toArray();

                        $dnItems = DeliveryNoteItem::where('delivery_note_id', $sr->delivery_note_id)
                            ->with('productVariant')
                            ->get()
                            ->filter(fn (DeliveryNoteItem $item) => bccomp($item->remainingReturnableQuantity(), '0', 4) > 0)
                            ->reject(fn (DeliveryNoteItem $item) => in_array($item->id, $existingDnItemIds));

                        if ($dnItems->isEmpty()) {
                            Notification::make()
                                ->title('No remaining items to import')
                                ->warning()
                                ->send();

                            return;
                        }

                        foreach ($dnItems as $dnItem) {
                            SalesReturnItem::create([
                                'sales_return_id' => $sr->id,
                                'delivery_note_item_id' => $dnItem->id,
                                'product_variant_id' => $dnItem->product_variant_id,
                                'quantity' => $dnItem->remainingReturnableQuantity(),
                                'unit_cost' => $dnItem->unit_cost,
                            ]);
                        }

                        Notification::make()
                            ->title('Items imported from Delivery Note')
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (empty($data['product_variant_id']) && ! empty($data['delivery_note_item_id'])) {
                            $dnItem = DeliveryNoteItem::find($data['delivery_note_item_id']);
                            if ($dnItem) {
                                $data['product_variant_id'] = $dnItem->product_variant_id;
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
