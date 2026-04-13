<?php

namespace App\Filament\Resources\SupplierInvoices\RelationManagers;

use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\VatRate;
use App\Services\SupplierInvoiceService;
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

class SupplierInvoiceItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Line Items';

    public function isReadOnly(): bool
    {
        /** @var SupplierInvoice $invoice */
        $invoice = $this->getOwnerRecord();

        return ! $invoice->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var SupplierInvoice $invoice */
        $invoice = $this->getOwnerRecord();

        $poItemOptions = [];
        if ($invoice->purchase_order_id) {
            $poItemOptions = PurchaseOrderItem::where('purchase_order_id', $invoice->purchase_order_id)
                ->with('productVariant')
                ->get()
                ->filter(fn (PurchaseOrderItem $item) => bccomp($item->remainingInvoiceableQuantity(), '0', 4) > 0)
                ->mapWithKeys(fn (PurchaseOrderItem $item) => [
                    $item->id => $item->productVariant
                        ? "{$item->productVariant->sku} — {$item->description} (remaining: {$item->remainingInvoiceableQuantity()})"
                        : "#{$item->id} — {$item->description} (remaining: {$item->remainingInvoiceableQuantity()})",
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
                            $poItem = PurchaseOrderItem::with(['productVariant', 'vatRate'])->find($state);
                            if ($poItem) {
                                $set('product_variant_id', $poItem->product_variant_id);
                                $set('description', $poItem->description
                                    ?? $poItem->productVariant?->getTranslation('name', app()->getLocale())
                                    ?? '');
                                $set('quantity', $poItem->remainingInvoiceableQuantity());
                                $set('unit_price', $poItem->unit_price);
                                $set('discount_percent', $poItem->discount_percent);
                                $set('vat_rate_id', $poItem->vat_rate_id);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Select::make('product_variant_id')
                    ->label('Product (leave blank for free-text line)')
                    ->options(ProductVariant::variantOptionsForSelect())
                    ->searchable()
                    ->nullable()
                    ->disabled(fn (Get $get): bool => ! empty($get('purchase_order_item_id')))
                    ->dehydrated()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        if ($state) {
                            $variant = ProductVariant::find($state);
                            if ($variant) {
                                $set('unit_price', $variant->purchase_price ?? '0.0000');
                                if (empty($get('description'))) {
                                    $set('description', $variant->getTranslation('name', app()->getLocale()) ?? '');
                                }
                                if ($variant->product?->vat_rate_id) {
                                    $set('vat_rate_id', $variant->product->vat_rate_id);
                                }
                            }
                        }
                    })
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull(),

                TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->minValue(0.0001)
                    ->step('0.0001')
                    ->default('1.0000'),

                TextInput::make('unit_price')
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
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('description')
                    ->limit(40),
                TextColumn::make('quantity')
                    ->numeric(4),
                TextColumn::make('unit_price')
                    ->numeric(4),
                TextColumn::make('discount_percent')
                    ->label('Disc%')
                    ->numeric(2)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vat_amount')
                    ->label('VAT')
                    ->numeric(2),
                TextColumn::make('line_total')
                    ->label('Net Total')
                    ->numeric(2),
            ])
            ->headerActions([
                Action::make('import_from_po')
                    ->label('Import from PO')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(function (): bool {
                        /** @var SupplierInvoice $invoice */
                        $invoice = $this->getOwnerRecord();

                        return $invoice->purchase_order_id !== null && $invoice->isEditable();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Import items from Purchase Order')
                    ->modalDescription('This will import all remaining uninvoiced PO items. You can edit quantities and prices afterward.')
                    ->action(function (): void {
                        /** @var SupplierInvoice $invoice */
                        $invoice = $this->getOwnerRecord();
                        $service = app(SupplierInvoiceService::class);

                        $existingPoItemIds = $invoice->items()
                            ->whereNotNull('purchase_order_item_id')
                            ->pluck('purchase_order_item_id')
                            ->toArray();

                        $poItems = PurchaseOrderItem::where('purchase_order_id', $invoice->purchase_order_id)
                            ->whereNotIn('id', $existingPoItemIds)
                            ->with(['productVariant', 'vatRate'])
                            ->get()
                            ->filter(fn (PurchaseOrderItem $item) => bccomp($item->remainingInvoiceableQuantity(), '0', 4) > 0);

                        if ($poItems->isEmpty()) {
                            Notification::make()
                                ->title('No remaining items to import')
                                ->warning()
                                ->send();

                            return;
                        }

                        foreach ($poItems as $poItem) {
                            $siItem = SupplierInvoiceItem::create([
                                'supplier_invoice_id' => $invoice->id,
                                'purchase_order_item_id' => $poItem->id,
                                'product_variant_id' => $poItem->product_variant_id,
                                'description' => $poItem->description
                                    ?? $poItem->productVariant?->getTranslation('name', app()->getLocale()) ?? '',
                                'quantity' => $poItem->remainingInvoiceableQuantity(),
                                'unit_price' => $poItem->unit_price,
                                'discount_percent' => $poItem->discount_percent,
                                'vat_rate_id' => $poItem->vat_rate_id,
                                'sort_order' => $poItem->sort_order,
                            ]);
                            $siItem->loadMissing(['supplierInvoice', 'vatRate']);
                            $service->recalculateItemTotals($siItem);
                        }

                        $service->recalculateDocumentTotals($invoice);

                        Notification::make()
                            ->title('Items imported from PO')
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->after(function (SupplierInvoiceItem $record): void {
                        $record->loadMissing(['supplierInvoice', 'vatRate']);
                        app(SupplierInvoiceService::class)->recalculateItemTotals($record);
                        app(SupplierInvoiceService::class)->recalculateDocumentTotals($record->supplierInvoice);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (SupplierInvoiceItem $record): void {
                        $record->loadMissing(['supplierInvoice', 'vatRate']);
                        app(SupplierInvoiceService::class)->recalculateItemTotals($record);
                        app(SupplierInvoiceService::class)->recalculateDocumentTotals($record->supplierInvoice);
                    }),
                DeleteAction::make()
                    ->after(function (SupplierInvoiceItem $record): void {
                        $invoice = SupplierInvoice::find($record->supplier_invoice_id);
                        if ($invoice) {
                            app(SupplierInvoiceService::class)->recalculateDocumentTotals($invoice);
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
