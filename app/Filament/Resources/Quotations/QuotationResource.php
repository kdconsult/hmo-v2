<?php

namespace App\Filament\Resources\Quotations;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Resources\Quotations\RelationManagers\QuotationItemsRelationManager;
use App\Filament\Resources\Quotations\Schemas\QuotationForm;
use App\Filament\Resources\Quotations\Tables\QuotationsTable;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\Quotation;
use App\Models\SalesOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'quotation_number';

    public static function form(Schema $schema): Schema
    {
        return QuotationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('no_items_notice')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state('No items added — this document cannot be confirmed until at least one item is added.')
                    ->visible(fn (Quotation $record): bool => $record->isEditable() && $record->items()->doesntExist()),

                Section::make('Quotation')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('quotation_number'),
                        TextEntry::make('partner.name')->label('Customer'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('issued_at')->date(),
                        TextEntry::make('valid_until')->date(),
                        TextEntry::make('currency_code')->label('Currency'),
                        TextEntry::make('pricing_mode'),
                    ]),

                Section::make('Totals')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('subtotal')->money(fn (Quotation $record): string => $record->currency_code),
                        TextEntry::make('discount_amount')->money(fn (Quotation $record): string => $record->currency_code),
                        TextEntry::make('tax_amount')->money(fn (Quotation $record): string => $record->currency_code),
                        TextEntry::make('total')->money(fn (Quotation $record): string => $record->currency_code),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('internal_notes')->label('Internal Notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (Quotation $record): bool => filled($record->notes) || filled($record->internal_notes)),

                Section::make('Related Documents')
                    ->schema([
                        RepeatableEntry::make('salesOrders')
                            ->label('Sales Orders')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('so_number')
                                    ->label('Order Number')
                                    ->url(fn (SalesOrder $record): string => SalesOrderResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('status')->badge(),
                            ]),
                    ])
                    ->visible(fn (Quotation $record): bool => $record->salesOrders()->exists()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return QuotationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            QuotationItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'view' => ViewQuotation::route('/{record}'),
            'edit' => EditQuotation::route('/{record}/edit'),
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
