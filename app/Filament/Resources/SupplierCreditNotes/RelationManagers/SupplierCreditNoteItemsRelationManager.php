<?php

namespace App\Filament\Resources\SupplierCreditNotes\RelationManagers;

use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteItem;
use App\Models\SupplierInvoiceItem;
use App\Models\VatRate;
use App\Services\SupplierCreditNoteService;
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
use Illuminate\Support\Facades\DB;

class SupplierCreditNoteItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Credit Note Items';

    public function isReadOnly(): bool
    {
        /** @var SupplierCreditNote $creditNote */
        $creditNote = $this->getOwnerRecord();

        return ! $creditNote->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var SupplierCreditNote $creditNote */
        $creditNote = $this->getOwnerRecord();

        $invoiceItems = SupplierInvoiceItem::where('supplier_invoice_id', $creditNote->supplier_invoice_id)
            ->with('productVariant')
            ->get()
            ->mapWithKeys(fn (SupplierInvoiceItem $item) => [
                $item->id => $item->productVariant
                    ? "{$item->productVariant->sku} — {$item->description} (max: {$item->remainingCreditableQuantity()})"
                    : $item->description." (max: {$item->remainingCreditableQuantity()})",
            ]);

        return $schema
            ->columns(2)
            ->components([
                Select::make('supplier_invoice_item_id')
                    ->label('Invoice Line Item')
                    ->options($invoiceItems)
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state) {
                            $invoiceItem = SupplierInvoiceItem::with('vatRate')->find($state);
                            if ($invoiceItem) {
                                $set('product_variant_id', $invoiceItem->product_variant_id);
                                $set('description', $invoiceItem->description ?? '');
                                $set('unit_price', $invoiceItem->unit_price);
                                $set('vat_rate_id', $invoiceItem->vat_rate_id);
                                $set('quantity', $invoiceItem->remainingCreditableQuantity());
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
                    ->default('1.0000')
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $invoiceItemId = $get('supplier_invoice_item_id');
                            if (! $invoiceItemId) {
                                return;
                            }

                            DB::transaction(function () use ($invoiceItemId, $value, $fail): void {
                                $invoiceItem = SupplierInvoiceItem::lockForUpdate()->find($invoiceItemId);
                                if (! $invoiceItem) {
                                    return;
                                }

                                $remaining = $invoiceItem->remainingCreditableQuantity();
                                if (bccomp((string) $value, $remaining, 4) > 0) {
                                    $fail("Quantity exceeds remaining creditable quantity ({$remaining}).");
                                }
                            });
                        },
                    ]),

                TextInput::make('unit_price')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step('0.0001')
                    ->default('0.0000'),

                Select::make('vat_rate_id')
                    ->label('VAT Rate')
                    ->options(VatRate::active()->orderBy('rate')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                TextInput::make('product_variant_id')
                    ->hidden()
                    ->dehydrated(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplierInvoiceItem.description')
                    ->label('Invoice Line'),
                TextColumn::make('quantity')
                    ->numeric(4),
                TextColumn::make('unit_price')
                    ->numeric(4),
                TextColumn::make('vat_amount')
                    ->label('VAT')
                    ->numeric(2),
                TextColumn::make('line_total')
                    ->label('Net Total')
                    ->numeric(2),
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (SupplierCreditNoteItem $record): void {
                        $record->loadMissing(['supplierCreditNote', 'vatRate']);
                        app(SupplierCreditNoteService::class)->recalculateItemTotals($record);
                        app(SupplierCreditNoteService::class)->recalculateDocumentTotals($record->supplierCreditNote);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (SupplierCreditNoteItem $record): void {
                        $record->loadMissing(['supplierCreditNote', 'vatRate']);
                        app(SupplierCreditNoteService::class)->recalculateItemTotals($record);
                        app(SupplierCreditNoteService::class)->recalculateDocumentTotals($record->supplierCreditNote);
                    }),
                DeleteAction::make()
                    ->after(function (SupplierCreditNoteItem $record): void {
                        $creditNote = SupplierCreditNote::find($record->supplier_credit_note_id);
                        if ($creditNote) {
                            app(SupplierCreditNoteService::class)->recalculateDocumentTotals($creditNote);
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
