<?php

namespace App\Filament\Resources\CustomerDebitNotes;

use App\Enums\NavigationGroup;
use App\Filament\Resources\CustomerDebitNotes\Pages\CreateCustomerDebitNote;
use App\Filament\Resources\CustomerDebitNotes\Pages\EditCustomerDebitNote;
use App\Filament\Resources\CustomerDebitNotes\Pages\ListCustomerDebitNotes;
use App\Filament\Resources\CustomerDebitNotes\Pages\ViewCustomerDebitNote;
use App\Filament\Resources\CustomerDebitNotes\RelationManagers\CustomerDebitNoteItemsRelationManager;
use App\Filament\Resources\CustomerDebitNotes\Schemas\CustomerDebitNoteForm;
use App\Filament\Resources\CustomerDebitNotes\Tables\CustomerDebitNotesTable;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Models\CustomerDebitNote;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerDebitNoteResource extends Resource
{
    protected static ?string $model = CustomerDebitNote::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'debit_note_number';

    public static function form(Schema $schema): Schema
    {
        return CustomerDebitNoteForm::configure($schema);
    }

    // CDN-V1: Proper infolist sections for CDN view
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('no_items_notice')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state('No items added — this document cannot be confirmed until at least one item is added.')
                    ->visible(fn (CustomerDebitNote $record): bool => $record->isEditable() && $record->items()->doesntExist()),

                Section::make('Debit Note')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('debit_note_number')->label('Debit Note Number'),
                        TextEntry::make('partner.name')->label('Customer'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('issued_at')->date(),
                        TextEntry::make('currency_code')->label('Currency'),
                        TextEntry::make('reason')->badge(),
                        TextEntry::make('reason_description')->label('Reason Details')->columnSpanFull()
                            ->placeholder('—')
                            ->visible(fn (CustomerDebitNote $record): bool => filled($record->reason_description)),
                    ]),

                Section::make('Totals')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('subtotal')->money(fn (CustomerDebitNote $record): string => $record->currency_code),
                        TextEntry::make('tax_amount')->money(fn (CustomerDebitNote $record): string => $record->currency_code),
                        TextEntry::make('total')->money(fn (CustomerDebitNote $record): string => $record->currency_code),
                    ]),

                Section::make('Related Documents')
                    ->schema([
                        TextEntry::make('customerInvoice.invoice_number')
                            ->label('Customer Invoice')
                            ->url(fn (CustomerDebitNote $record): string => CustomerInvoiceResource::getUrl('view', ['record' => $record->customer_invoice_id]))
                            ->visible(fn (CustomerDebitNote $record): bool => $record->customer_invoice_id !== null),
                    ])
                    ->visible(fn (CustomerDebitNote $record): bool => $record->customer_invoice_id !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return CustomerDebitNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CustomerDebitNoteItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerDebitNotes::route('/'),
            'create' => CreateCustomerDebitNote::route('/create'),
            'view' => ViewCustomerDebitNote::route('/{record}'),
            'edit' => EditCustomerDebitNote::route('/{record}/edit'),
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
