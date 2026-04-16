<?php

namespace App\Filament\Resources\CustomerInvoices;

use App\Enums\NavigationGroup;
use App\Filament\Resources\AdvancePayments\AdvancePaymentResource;
use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use App\Filament\Resources\CustomerDebitNotes\CustomerDebitNoteResource;
use App\Filament\Resources\CustomerInvoices\Pages\CreateCustomerInvoice;
use App\Filament\Resources\CustomerInvoices\Pages\EditCustomerInvoice;
use App\Filament\Resources\CustomerInvoices\Pages\ListCustomerInvoices;
use App\Filament\Resources\CustomerInvoices\Pages\ViewCustomerInvoice;
use App\Filament\Resources\CustomerInvoices\RelationManagers\CustomerInvoiceItemsRelationManager;
use App\Filament\Resources\CustomerInvoices\Schemas\CustomerInvoiceForm;
use App\Filament\Resources\CustomerInvoices\Tables\CustomerInvoicesTable;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\AdvancePaymentApplication;
use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerInvoiceResource extends Resource
{
    protected static ?string $model = CustomerInvoice::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function form(Schema $schema): Schema
    {
        return CustomerInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('no_items_notice')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state('No items added — this document cannot be confirmed until at least one item is added.')
                    ->visible(fn (CustomerInvoice $record): bool => $record->isEditable() && $record->items()->doesntExist()),

                Section::make('Invoice')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('invoice_number'),
                        TextEntry::make('partner.name')->label('Customer'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('invoice_type')->label('Type')->badge(),
                        TextEntry::make('issued_at')->date(),
                        TextEntry::make('due_date')->date()->label('Due Date'),
                        TextEntry::make('currency_code')->label('Currency'),
                        TextEntry::make('pricing_mode'),
                        TextEntry::make('payment_method')->label('Payment Method'),
                    ]),
                Grid::make()
                    ->columns(1)
                    ->schema([
                        // CI-V3: Tax Breakdown
                        Section::make('Tax Breakdown')
                            ->schema([
                                TextEntry::make('tax_breakdown')
                                    ->label('VAT by Rate')
                                    ->html()
                                    ->state(fn (CustomerInvoice $record): string => $record
                                        ->loadMissing('items.vatRate')
                                        ->items
                                        ->filter(fn ($item) => (float) $item->vat_amount > 0)
                                        ->groupBy(fn ($item) => $item->vatRate?->name ?? 'Unknown Rate')
                                        ->map(fn ($items, $rateName) => $rateName.': €'.number_format(
                                            $items->sum(fn ($i) => (float) $i->vat_amount), 2
                                        ))
                                        ->join('<br>') ?: 'No VAT applicable')
                                    ->columnSpanFull(),
                            ])
                            ->visible(fn (CustomerInvoice $record): bool => (float) $record->tax_amount > 0),

                        // CI-V4 + CI-1: Totals and Payment Status
                        Section::make('Totals')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('subtotal')->money(fn (CustomerInvoice $record): string => $record->currency_code),
                                TextEntry::make('tax_amount')->money(fn (CustomerInvoice $record): string => $record->currency_code),
                                TextEntry::make('total')->money(fn (CustomerInvoice $record): string => $record->currency_code),
                                TextEntry::make('discount_amount')->money(fn (CustomerInvoice $record): string => $record->currency_code),

                                // CI-1: Advance deductions
                                TextEntry::make('advance_deductions')
                                    ->label('Advance Deductions Applied')
                                    ->state(fn (CustomerInvoice $record): float => (float) $record->advancePaymentApplications->sum('amount_applied'))
                                    ->money(fn (CustomerInvoice $record): string => $record->currency_code)
                                    ->visible(fn (CustomerInvoice $record): bool => $record->advancePaymentApplications->isNotEmpty()),

                                // CI-V4
                                TextEntry::make('amount_paid')->money(fn (CustomerInvoice $record): string => $record->currency_code),
                                TextEntry::make('amount_due')->label('Balance Due')->money(fn (CustomerInvoice $record): string => $record->currency_code),
                            ]),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('internal_notes')->label('Internal Notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (CustomerInvoice $record): bool => filled($record->notes) || filled($record->internal_notes)),

                Section::make('Related Documents')
                    ->schema([
                        TextEntry::make('salesOrder.so_number')
                            ->label('Sales Order')
                            ->url(fn (CustomerInvoice $record): string => SalesOrderResource::getUrl('view', ['record' => $record->sales_order_id]))
                            ->visible(fn (CustomerInvoice $record): bool => $record->sales_order_id !== null),

                        RepeatableEntry::make('creditNotes')
                            ->label('Credit Notes')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('credit_note_number')
                                    ->label('Credit Note')
                                    ->url(fn (CustomerCreditNote $record): string => CustomerCreditNoteResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('status')->badge(),
                            ]),

                        RepeatableEntry::make('debitNotes')
                            ->label('Debit Notes')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('debit_note_number')
                                    ->label('Debit Note')
                                    ->url(fn (CustomerDebitNote $record): string => CustomerDebitNoteResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('status')->badge(),
                            ]),

                        RepeatableEntry::make('advancePaymentApplications')
                            ->label('Applied Advance Payments')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('advancePayment.ap_number')
                                    ->label('Advance Payment')
                                    ->url(fn (AdvancePaymentApplication $record): string => AdvancePaymentResource::getUrl('view', ['record' => $record->advance_payment_id])),
                                TextEntry::make('amount_applied')->money('EUR'),
                            ]),
                    ])
                    ->visible(fn (CustomerInvoice $record): bool => $record->sales_order_id !== null
                        || $record->creditNotes()->exists()
                        || $record->debitNotes()->exists()
                        || $record->advancePaymentApplications()->exists()
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return CustomerInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CustomerInvoiceItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerInvoices::route('/'),
            'create' => CreateCustomerInvoice::route('/create'),
            'view' => ViewCustomerInvoice::route('/{record}'),
            'edit' => EditCustomerInvoice::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
