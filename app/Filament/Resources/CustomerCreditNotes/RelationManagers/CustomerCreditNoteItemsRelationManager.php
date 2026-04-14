<?php

namespace App\Filament\Resources\CustomerCreditNotes\RelationManagers;

use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteItem;
use App\Models\CustomerInvoiceItem;
use App\Models\VatRate;
use App\Services\CustomerCreditNoteService;
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

class CustomerCreditNoteItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Credit Note Items';

    public function isReadOnly(): bool
    {
        /** @var CustomerCreditNote $creditNote */
        $creditNote = $this->getOwnerRecord();

        return ! $creditNote->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        /** @var CustomerCreditNote $creditNote */
        $creditNote = $this->getOwnerRecord();

        $invoiceItems = CustomerInvoiceItem::where('customer_invoice_id', $creditNote->customer_invoice_id)
            ->with('productVariant')
            ->get()
            ->mapWithKeys(fn (CustomerInvoiceItem $item) => [
                $item->id => $item->productVariant
                    ? "{$item->productVariant->sku} — {$item->description} (max: {$item->remainingCreditableQuantity()})"
                    : $item->description." (max: {$item->remainingCreditableQuantity()})",
            ]);

        return $schema
            ->columns(2)
            ->components([
                Select::make('customer_invoice_item_id')
                    ->label('Invoice Line Item')
                    ->options($invoiceItems)
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state) {
                            $invoiceItem = CustomerInvoiceItem::with('vatRate')->find($state);
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
                            $invoiceItemId = $get('customer_invoice_item_id');
                            if (! $invoiceItemId) {
                                return;
                            }

                            DB::transaction(function () use ($invoiceItemId, $value, $fail): void {
                                $invoiceItem = CustomerInvoiceItem::lockForUpdate()->find($invoiceItemId);
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
                TextColumn::make('customerInvoiceItem.description')
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
                    ->after(function (CustomerCreditNoteItem $record): void {
                        $record->loadMissing(['customerCreditNote', 'vatRate']);
                        app(CustomerCreditNoteService::class)->recalculateItemTotals($record);
                        app(CustomerCreditNoteService::class)->recalculateDocumentTotals($record->customerCreditNote);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (CustomerCreditNoteItem $record): void {
                        $record->loadMissing(['customerCreditNote', 'vatRate']);
                        app(CustomerCreditNoteService::class)->recalculateItemTotals($record);
                        app(CustomerCreditNoteService::class)->recalculateDocumentTotals($record->customerCreditNote);
                    }),
                DeleteAction::make()
                    ->after(function (CustomerCreditNoteItem $record): void {
                        $creditNote = CustomerCreditNote::find($record->customer_credit_note_id);
                        if ($creditNote) {
                            app(CustomerCreditNoteService::class)->recalculateDocumentTotals($creditNote);
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
