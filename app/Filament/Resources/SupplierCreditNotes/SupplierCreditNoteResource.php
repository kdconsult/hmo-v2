<?php

namespace App\Filament\Resources\SupplierCreditNotes;

use App\Enums\NavigationGroup;
use App\Filament\Resources\SupplierCreditNotes\Pages\CreateSupplierCreditNote;
use App\Filament\Resources\SupplierCreditNotes\Pages\EditSupplierCreditNote;
use App\Filament\Resources\SupplierCreditNotes\Pages\ListSupplierCreditNotes;
use App\Filament\Resources\SupplierCreditNotes\Pages\ViewSupplierCreditNote;
use App\Filament\Resources\SupplierCreditNotes\RelationManagers\SupplierCreditNoteItemsRelationManager;
use App\Filament\Resources\SupplierCreditNotes\Schemas\SupplierCreditNoteForm;
use App\Filament\Resources\SupplierCreditNotes\Tables\SupplierCreditNotesTable;
use App\Models\SupplierCreditNote;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierCreditNoteResource extends Resource
{
    protected static ?string $model = SupplierCreditNote::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Purchases;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'credit_note_number';

    public static function form(Schema $schema): Schema
    {
        return SupplierCreditNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierCreditNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SupplierCreditNoteItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierCreditNotes::route('/'),
            'create' => CreateSupplierCreditNote::route('/create'),
            'view' => ViewSupplierCreditNote::route('/{record}'),
            'edit' => EditSupplierCreditNote::route('/{record}/edit'),
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
