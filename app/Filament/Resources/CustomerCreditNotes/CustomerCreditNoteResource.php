<?php

namespace App\Filament\Resources\CustomerCreditNotes;

use App\Enums\NavigationGroup;
use App\Filament\Resources\CustomerCreditNotes\Pages\CreateCustomerCreditNote;
use App\Filament\Resources\CustomerCreditNotes\Pages\EditCustomerCreditNote;
use App\Filament\Resources\CustomerCreditNotes\Pages\ListCustomerCreditNotes;
use App\Filament\Resources\CustomerCreditNotes\Pages\ViewCustomerCreditNote;
use App\Filament\Resources\CustomerCreditNotes\RelationManagers\CustomerCreditNoteItemsRelationManager;
use App\Filament\Resources\CustomerCreditNotes\Schemas\CustomerCreditNoteForm;
use App\Filament\Resources\CustomerCreditNotes\Tables\CustomerCreditNotesTable;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Models\CustomerCreditNote;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerCreditNoteResource extends Resource
{
    protected static ?string $model = CustomerCreditNote::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'credit_note_number';

    public static function form(Schema $schema): Schema
    {
        return CustomerCreditNoteForm::configure($schema);
    }

    // CCN-V1: Proper infolist sections for CCN view
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('no_items_notice')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state('No items added — this document cannot be confirmed until at least one item is added.')
                    ->visible(fn (CustomerCreditNote $record): bool => $record->isEditable() && $record->items()->doesntExist()),

                Section::make('Credit Note')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('credit_note_number')->label('Credit Note Number'),
                        TextEntry::make('partner.name')->label('Customer'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('issued_at')->date(),
                        TextEntry::make('currency_code')->label('Currency'),
                        TextEntry::make('reason')->badge(),
                        TextEntry::make('reason_description')->label('Reason Details')->columnSpanFull()
                            ->placeholder('—')
                            ->visible(fn (CustomerCreditNote $record): bool => filled($record->reason_description)),
                    ]),

                Section::make('Totals')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('subtotal')->money(fn (CustomerCreditNote $record): string => $record->currency_code),
                        TextEntry::make('tax_amount')->money(fn (CustomerCreditNote $record): string => $record->currency_code),
                        TextEntry::make('total')->money(fn (CustomerCreditNote $record): string => $record->currency_code),
                    ]),

                Section::make('Related Documents')
                    ->schema([
                        TextEntry::make('customerInvoice.invoice_number')
                            ->label('Customer Invoice')
                            ->url(fn (CustomerCreditNote $record): string => CustomerInvoiceResource::getUrl('view', ['record' => $record->customer_invoice_id])),

                        TextEntry::make('salesReturn.sr_number')
                            ->label('Sales Return')
                            ->url(fn (CustomerCreditNote $record): string => SalesReturnResource::getUrl('view', ['record' => $record->sales_return_id]))
                            ->visible(fn (CustomerCreditNote $record): bool => $record->sales_return_id !== null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return CustomerCreditNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CustomerCreditNoteItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerCreditNotes::route('/'),
            'create' => CreateCustomerCreditNote::route('/create'),
            'view' => ViewCustomerCreditNote::route('/{record}'),
            'edit' => EditCustomerCreditNote::route('/{record}/edit'),
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
