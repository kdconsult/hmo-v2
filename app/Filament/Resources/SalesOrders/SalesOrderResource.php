<?php

namespace App\Filament\Resources\SalesOrders;

use App\Enums\NavigationGroup;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Filament\Resources\SalesOrders\RelationManagers\SalesOrderItemsRelationManager;
use App\Filament\Resources\SalesOrders\Schemas\SalesOrderForm;
use App\Filament\Resources\SalesOrders\Tables\SalesOrdersTable;
use App\Models\CustomerInvoice;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SalesOrderResource extends Resource
{
    protected static ?string $model = SalesOrder::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'so_number';

    public static function form(Schema $schema): Schema
    {
        return SalesOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('no_items_notice')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state('No items added — this document cannot be confirmed until at least one item is added.')
                    ->visible(fn (SalesOrder $record): bool => $record->isEditable() && $record->items()->doesntExist()),

                Section::make('Sales Order')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('so_number')->label('Order Number'),
                        TextEntry::make('partner.name')->label('Customer'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('issued_at')->date(),
                        TextEntry::make('expected_delivery_date')->date()->label('Expected Delivery'),
                        TextEntry::make('warehouse.name')->label('Warehouse'),
                        TextEntry::make('currency_code')->label('Currency'),
                        TextEntry::make('pricing_mode'),
                    ]),

                Section::make('Totals')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('subtotal')->money(fn (SalesOrder $record): string => $record->currency_code),
                        TextEntry::make('discount_amount')->money(fn (SalesOrder $record): string => $record->currency_code),
                        TextEntry::make('tax_amount')->money(fn (SalesOrder $record): string => $record->currency_code),
                        TextEntry::make('total')->money(fn (SalesOrder $record): string => $record->currency_code),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('internal_notes')->label('Internal Notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (SalesOrder $record): bool => filled($record->notes) || filled($record->internal_notes)),

                Section::make('Related Documents')
                    ->schema([
                        TextEntry::make('quotation.quotation_number')
                            ->label('Source Quotation')
                            ->url(fn (SalesOrder $record): string => QuotationResource::getUrl('view', ['record' => $record->quotation_id]))
                            ->visible(fn (SalesOrder $record): bool => $record->quotation_id !== null),

                        RepeatableEntry::make('deliveryNotes')
                            ->label('Delivery Notes')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('dn_number')
                                    ->label('DN Number')
                                    ->url(fn (DeliveryNote $record): string => DeliveryNoteResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('status')->badge(),
                            ]),

                        RepeatableEntry::make('customerInvoices')
                            ->label('Customer Invoices')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('invoice_number')
                                    ->label('Invoice Number')
                                    ->url(fn (CustomerInvoice $record): string => CustomerInvoiceResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('status')->badge(),
                            ]),
                    ])
                    ->visible(fn (SalesOrder $record): bool => $record->quotation_id !== null
                        || $record->deliveryNotes()->exists()
                        || $record->customerInvoices()->exists()
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return SalesOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SalesOrderItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesOrders::route('/'),
            'create' => CreateSalesOrder::route('/create'),
            'view' => ViewSalesOrder::route('/{record}'),
            'edit' => EditSalesOrder::route('/{record}/edit'),
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
