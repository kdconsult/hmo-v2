<?php

namespace App\Filament\Resources\SupplierInvoices\RelationManagers;

use App\Models\ProductVariant;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\VatRate;
use App\Services\SupplierInvoiceService;
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
        return $schema
            ->columns(2)
            ->components([
                Select::make('product_variant_id')
                    ->label('Product (leave blank for free-text line)')
                    ->options(
                        ProductVariant::with('product')
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $v) => [
                                $v->id => "{$v->sku} — {$v->product->name}",
                            ])
                    )
                    ->searchable()
                    ->nullable()
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
