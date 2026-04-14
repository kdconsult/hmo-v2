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
use App\Models\CustomerDebitNote;
use Filament\Resources\Resource;
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
