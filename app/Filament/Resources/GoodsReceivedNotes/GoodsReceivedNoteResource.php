<?php

namespace App\Filament\Resources\GoodsReceivedNotes;

use App\Enums\NavigationGroup;
use App\Filament\Resources\GoodsReceivedNotes\Pages\CreateGoodsReceivedNote;
use App\Filament\Resources\GoodsReceivedNotes\Pages\EditGoodsReceivedNote;
use App\Filament\Resources\GoodsReceivedNotes\Pages\ListGoodsReceivedNotes;
use App\Filament\Resources\GoodsReceivedNotes\Pages\ViewGoodsReceivedNote;
use App\Filament\Resources\GoodsReceivedNotes\RelationManagers\GoodsReceivedNoteItemsRelationManager;
use App\Filament\Resources\GoodsReceivedNotes\Schemas\GoodsReceivedNoteForm;
use App\Filament\Resources\GoodsReceivedNotes\Tables\GoodsReceivedNotesTable;
use App\Models\GoodsReceivedNote;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GoodsReceivedNoteResource extends Resource
{
    protected static ?string $model = GoodsReceivedNote::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Purchases;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Goods Receipts';

    protected static ?string $recordTitleAttribute = 'grn_number';

    public static function form(Schema $schema): Schema
    {
        return GoodsReceivedNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoodsReceivedNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            GoodsReceivedNoteItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsReceivedNotes::route('/'),
            'create' => CreateGoodsReceivedNote::route('/create'),
            'view' => ViewGoodsReceivedNote::route('/{record}'),
            'edit' => EditGoodsReceivedNote::route('/{record}/edit'),
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
