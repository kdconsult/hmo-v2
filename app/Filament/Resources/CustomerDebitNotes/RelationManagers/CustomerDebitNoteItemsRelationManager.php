<?php

namespace App\Filament\Resources\CustomerDebitNotes\RelationManagers;

use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteItem;
use App\Models\CustomerInvoiceItem;
use App\Models\ProductVariant;
use App\Models\VatRate;
use App\Services\CustomerDebitNoteService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomerDebitNoteItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Debit Note Items';

    public function isReadOnly(): bool
    {
        /** @var CustomerDebitNote $debitNote */
        $debitNote = $this->getOwnerRecord();

        return ! $debitNote->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var CustomerDebitNote $debitNote */
        $debitNote = $this->getOwnerRecord();

        $invoiceItems = [];
        if ($debitNote->customer_invoice_id) {
            $invoiceItems = CustomerInvoiceItem::where('customer_invoice_id', $debitNote->customer_invoice_id)
                ->with('productVariant')
                ->get()
                ->mapWithKeys(fn (CustomerInvoiceItem $item) => [
                    $item->id => $item->productVariant
                        ? "{$item->productVariant->sku} — {$item->description}"
                        : $item->description,
                ])
                ->all();
        }

        return $schema
            ->columns(2)
            ->components([
                Select::make('customer_invoice_item_id')
                    ->label('Invoice Line Item (optional)')
                    ->options($invoiceItems)
                    ->searchable()
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state) {
                            $invoiceItem = CustomerInvoiceItem::with('vatRate')->find($state);
                            if ($invoiceItem) {
                                $set('product_variant_id', $invoiceItem->product_variant_id);
                                $set('description', $invoiceItem->description ?? '');
                                $set('unit_price', $invoiceItem->unit_price);
                                $set('vat_rate_id', $invoiceItem->vat_rate_id);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Select::make('product_variant_id')
                    ->label('Product Variant (optional)')
                    ->options(
                        ProductVariant::with('product')
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $v) => [
                                $v->id => "{$v->sku} — {$v->product->name}",
                            ])
                    )
                    ->searchable()
                    ->nullable()
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

                Select::make('vat_rate_id')
                    ->label('VAT Rate')
                    ->options(VatRate::active()->orderBy('rate')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')
                    ->limit(50),
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
                    ->after(function (CustomerDebitNoteItem $record): void {
                        $record->loadMissing(['customerDebitNote', 'vatRate']);
                        app(CustomerDebitNoteService::class)->recalculateItemTotals($record);
                        app(CustomerDebitNoteService::class)->recalculateDocumentTotals($record->customerDebitNote);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (CustomerDebitNoteItem $record): void {
                        $record->loadMissing(['customerDebitNote', 'vatRate']);
                        app(CustomerDebitNoteService::class)->recalculateItemTotals($record);
                        app(CustomerDebitNoteService::class)->recalculateDocumentTotals($record->customerDebitNote);
                    }),
                DeleteAction::make()
                    ->after(function (CustomerDebitNoteItem $record): void {
                        $debitNote = CustomerDebitNote::find($record->customer_debit_note_id);
                        if ($debitNote) {
                            app(CustomerDebitNoteService::class)->recalculateDocumentTotals($debitNote);
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
