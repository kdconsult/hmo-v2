<?php

namespace App\Filament\Resources\CustomerDebitNotes\RelationManagers;

use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteItem;
use App\Models\CustomerInvoiceItem;
use App\Models\ProductVariant;
use App\Models\VatRate;
use App\Services\CustomerDebitNoteService;
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

                                if (bccomp((string) $value, (string) $invoiceItem->quantity, 4) > 0) {
                                    $fail("Quantity exceeds the invoiced quantity ({$invoiceItem->quantity}).");
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
                Action::make('import_from_invoice')
                    ->label('Import from Invoice')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(function (): bool {
                        /** @var CustomerDebitNote $debitNote */
                        $debitNote = $this->getOwnerRecord();

                        return $debitNote->customer_invoice_id !== null && $debitNote->isEditable();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Import items from Customer Invoice')
                    ->modalDescription('This will add all invoice items as debit note items. You can edit individual quantities and prices afterward.')
                    ->action(function (): void {
                        /** @var CustomerDebitNote $debitNote */
                        $debitNote = $this->getOwnerRecord();

                        $existingInvoiceItemIds = CustomerDebitNoteItem::where('customer_debit_note_id', $debitNote->id)
                            ->whereNotNull('customer_invoice_item_id')
                            ->pluck('customer_invoice_item_id')
                            ->toArray();

                        $invoiceItems = CustomerInvoiceItem::where('customer_invoice_id', $debitNote->customer_invoice_id)
                            ->with('vatRate')
                            ->get()
                            ->reject(fn (CustomerInvoiceItem $item) => in_array($item->id, $existingInvoiceItemIds));

                        if ($invoiceItems->isEmpty()) {
                            Notification::make()
                                ->title('No remaining items to import')
                                ->warning()
                                ->send();

                            return;
                        }

                        $service = app(CustomerDebitNoteService::class);

                        foreach ($invoiceItems as $invoiceItem) {
                            $item = CustomerDebitNoteItem::create([
                                'customer_debit_note_id' => $debitNote->id,
                                'customer_invoice_item_id' => $invoiceItem->id,
                                'product_variant_id' => $invoiceItem->product_variant_id,
                                'description' => $invoiceItem->description,
                                'quantity' => $invoiceItem->quantity,
                                'unit_price' => $invoiceItem->unit_price,
                                'vat_rate_id' => $invoiceItem->vat_rate_id,
                                'vat_amount' => '0.00',
                                'line_total' => '0.00',
                                'line_total_with_vat' => '0.00',
                                'sort_order' => 0,
                            ]);
                            $item->setRelation('customerDebitNote', $debitNote);
                            $item->setRelation('vatRate', $invoiceItem->vatRate);
                            $service->recalculateItemTotals($item);
                        }

                        $service->recalculateDocumentTotals($debitNote);

                        Notification::make()
                            ->title('Items imported from Invoice')
                            ->success()
                            ->send();
                    }),
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
