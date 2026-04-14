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
use App\Models\CustomerCreditNote;
use Filament\Resources\Resource;
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
