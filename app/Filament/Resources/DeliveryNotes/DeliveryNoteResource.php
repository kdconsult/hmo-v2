<?php

namespace App\Filament\Resources\DeliveryNotes;

use App\Enums\NavigationGroup;
use App\Filament\Resources\DeliveryNotes\Pages\CreateDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Pages\EditDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Filament\Resources\DeliveryNotes\RelationManagers\DeliveryNoteItemsRelationManager;
use App\Filament\Resources\DeliveryNotes\Schemas\DeliveryNoteForm;
use App\Filament\Resources\DeliveryNotes\Tables\DeliveryNotesTable;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Models\DeliveryNote;
use App\Models\SalesReturn;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DeliveryNoteResource extends Resource
{
    protected static ?string $model = DeliveryNote::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Delivery Notes';

    protected static ?string $recordTitleAttribute = 'dn_number';

    public static function form(Schema $schema): Schema
    {
        return DeliveryNoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('no_items_notice')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state('No items added — this document cannot be confirmed until at least one item is added.')
                    ->visible(fn (DeliveryNote $record): bool => $record->isEditable() && $record->items()->doesntExist()),

                Section::make('Delivery Note')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('dn_number')->label('DN Number'),
                        TextEntry::make('partner.name')->label('Customer'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('delivered_at')->date()->label('Delivered Date'),
                        TextEntry::make('warehouse.name')->label('Warehouse'),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (DeliveryNote $record): bool => filled($record->notes)),

                Section::make('Related Documents')
                    ->schema([
                        TextEntry::make('salesOrder.so_number')
                            ->label('Sales Order')
                            ->url(fn (DeliveryNote $record): string => SalesOrderResource::getUrl('view', ['record' => $record->sales_order_id]))
                            ->visible(fn (DeliveryNote $record): bool => $record->sales_order_id !== null),

                        RepeatableEntry::make('salesReturns')
                            ->label('Sales Returns')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('sr_number')
                                    ->label('SR Number')
                                    ->url(fn (SalesReturn $record): string => SalesReturnResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('status')->badge(),
                            ]),
                    ])
                    ->visible(fn (DeliveryNote $record): bool => $record->sales_order_id !== null
                        || $record->salesReturns()->exists()
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return DeliveryNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DeliveryNoteItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryNotes::route('/'),
            'create' => CreateDeliveryNote::route('/create'),
            'view' => ViewDeliveryNote::route('/{record}'),
            'edit' => EditDeliveryNote::route('/{record}/edit'),
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
