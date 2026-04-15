<?php

namespace App\Filament\Resources\SalesReturns;

use App\Enums\NavigationGroup;
use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\SalesReturns\Pages\CreateSalesReturn;
use App\Filament\Resources\SalesReturns\Pages\EditSalesReturn;
use App\Filament\Resources\SalesReturns\Pages\ListSalesReturns;
use App\Filament\Resources\SalesReturns\Pages\ViewSalesReturn;
use App\Filament\Resources\SalesReturns\RelationManagers\SalesReturnItemsRelationManager;
use App\Filament\Resources\SalesReturns\Schemas\SalesReturnForm;
use App\Filament\Resources\SalesReturns\Tables\SalesReturnsTable;
use App\Models\CustomerCreditNote;
use App\Models\SalesReturn;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SalesReturnResource extends Resource
{
    protected static ?string $model = SalesReturn::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Sales Returns';

    protected static ?string $recordTitleAttribute = 'sr_number';

    public static function form(Schema $schema): Schema
    {
        return SalesReturnForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('no_items_notice')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state('No items added — this document cannot be confirmed until at least one item is added.')
                    ->visible(fn (SalesReturn $record): bool => $record->isEditable() && $record->items()->doesntExist()),

                Section::make('Sales Return')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('sr_number')->label('SR Number'),
                        TextEntry::make('partner.name')->label('Customer'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('returned_at')->date()->label('Returned Date'),
                        TextEntry::make('warehouse.name')->label('Warehouse'),
                        TextEntry::make('reason')->label('Reason')->placeholder('—'),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (SalesReturn $record): bool => filled($record->notes)),

                Section::make('Related Documents')
                    ->schema([
                        TextEntry::make('deliveryNote.dn_number')
                            ->label('Delivery Note')
                            ->url(fn (SalesReturn $record): string => DeliveryNoteResource::getUrl('view', ['record' => $record->delivery_note_id]))
                            ->visible(fn (SalesReturn $record): bool => $record->delivery_note_id !== null),

                        RepeatableEntry::make('customerCreditNotes')
                            ->label('Credit Notes')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('credit_note_number')
                                    ->label('Credit Note')
                                    ->url(fn (CustomerCreditNote $record): string => CustomerCreditNoteResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('status')->badge(),
                            ]),
                    ])
                    ->visible(fn (SalesReturn $record): bool => $record->delivery_note_id !== null
                        || $record->customerCreditNotes()->exists()
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return SalesReturnsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SalesReturnItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesReturns::route('/'),
            'create' => CreateSalesReturn::route('/create'),
            'view' => ViewSalesReturn::route('/{record}'),
            'edit' => EditSalesReturn::route('/{record}/edit'),
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
