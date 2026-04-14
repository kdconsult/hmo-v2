<?php

namespace App\Filament\Resources\CustomerInvoices\RelationManagers;

use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use App\Models\VatRate;
use App\Services\CustomerInvoiceService;
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

class CustomerInvoiceItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Line Items';

    public function isReadOnly(): bool
    {
        /** @var CustomerInvoice $invoice */
        $invoice = $this->getOwnerRecord();

        return ! $invoice->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var CustomerInvoice $invoice */
        $invoice = $this->getOwnerRecord();

        $soItemOptions = [];
        if ($invoice->sales_order_id) {
            $soItemOptions = SalesOrderItem::where('sales_order_id', $invoice->sales_order_id)
                ->with('productVariant')
                ->get()
                ->filter(fn (SalesOrderItem $item) => bccomp($item->remainingInvoiceableQuantity(), '0', 4) > 0)
                ->mapWithKeys(fn (SalesOrderItem $item) => [
                    $item->id => $item->productVariant
                        ? "{$item->productVariant->sku} — {$item->description} (remaining: {$item->remainingInvoiceableQuantity()})"
                        : "#{$item->id} — {$item->description} (remaining: {$item->remainingInvoiceableQuantity()})",
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
                            $soItem = SalesOrderItem::with(['productVariant', 'vatRate'])->find($state);
                            if ($soItem) {
                                $set('product_variant_id', $soItem->product_variant_id);
                                $set('description', $soItem->description
                                    ?? $soItem->productVariant?->getTranslation('name', app()->getLocale())
                                    ?? '');
                                $set('quantity', $soItem->remainingInvoiceableQuantity());
                                $set('unit_price', $soItem->unit_price);
                                $set('discount_percent', $soItem->discount_percent);
                                $set('vat_rate_id', $soItem->vat_rate_id);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Select::make('product_variant_id')
                    ->label('Product (leave blank for free-text line)')
                    ->options(ProductVariant::variantOptionsForSelect())
                    ->searchable()
                    ->nullable()
                    ->disabled(fn (Get $get): bool => ! empty($get('sales_order_item_id')))
                    ->dehydrated()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        if ($state) {
                            $variant = ProductVariant::find($state);
                            if ($variant) {
                                $set('unit_price', $variant->sale_price ?? '0.0000');
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
                Action::make('import_from_so')
                    ->label('Import from SO')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(function (): bool {
                        /** @var CustomerInvoice $invoice */
                        $invoice = $this->getOwnerRecord();

                        return $invoice->sales_order_id !== null && $invoice->isEditable();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Import items from Sales Order')
                    ->modalDescription('This will import all remaining uninvoiced SO items. You can edit quantities and prices afterward.')
                    ->action(function (): void {
                        /** @var CustomerInvoice $invoice */
                        $invoice = $this->getOwnerRecord();
                        $service = app(CustomerInvoiceService::class);

                        $existingSoItemIds = $invoice->items()
                            ->whereNotNull('sales_order_item_id')
                            ->pluck('sales_order_item_id')
                            ->toArray();

                        $soItems = SalesOrderItem::where('sales_order_id', $invoice->sales_order_id)
                            ->whereNotIn('id', $existingSoItemIds)
                            ->with(['productVariant', 'vatRate'])
                            ->get()
                            ->filter(fn (SalesOrderItem $item) => bccomp($item->remainingInvoiceableQuantity(), '0', 4) > 0);

                        if ($soItems->isEmpty()) {
                            Notification::make()
                                ->title('No remaining items to import')
                                ->warning()
                                ->send();

                            return;
                        }

                        foreach ($soItems as $soItem) {
                            $ciItem = CustomerInvoiceItem::create([
                                'customer_invoice_id' => $invoice->id,
                                'sales_order_item_id' => $soItem->id,
                                'product_variant_id' => $soItem->product_variant_id,
                                'description' => $soItem->description
                                    ?? $soItem->productVariant?->getTranslation('name', app()->getLocale()) ?? '',
                                'quantity' => $soItem->remainingInvoiceableQuantity(),
                                'unit_price' => $soItem->unit_price,
                                'discount_percent' => $soItem->discount_percent,
                                'vat_rate_id' => $soItem->vat_rate_id,
                                'sort_order' => $soItem->sort_order,
                            ]);
                            $ciItem->loadMissing(['customerInvoice', 'vatRate']);
                            $service->recalculateItemTotals($ciItem);
                        }

                        $service->recalculateDocumentTotals($invoice);

                        Notification::make()
                            ->title('Items imported from Sales Order')
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->after(function (CustomerInvoiceItem $record): void {
                        $record->loadMissing(['customerInvoice', 'vatRate']);
                        app(CustomerInvoiceService::class)->recalculateItemTotals($record);
                        app(CustomerInvoiceService::class)->recalculateDocumentTotals($record->customerInvoice);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (CustomerInvoiceItem $record): void {
                        $record->loadMissing(['customerInvoice', 'vatRate']);
                        app(CustomerInvoiceService::class)->recalculateItemTotals($record);
                        app(CustomerInvoiceService::class)->recalculateDocumentTotals($record->customerInvoice);
                    }),
                DeleteAction::make()
                    ->after(function (CustomerInvoiceItem $record): void {
                        $invoice = CustomerInvoice::find($record->customer_invoice_id);
                        if ($invoice) {
                            app(CustomerInvoiceService::class)->recalculateDocumentTotals($invoice);
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
