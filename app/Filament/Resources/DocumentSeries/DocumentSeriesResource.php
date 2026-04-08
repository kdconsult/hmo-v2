<?php

namespace App\Filament\Resources\DocumentSeries;

use App\Filament\Resources\DocumentSeries\Pages\CreateDocumentSeries;
use App\Filament\Resources\DocumentSeries\Pages\EditDocumentSeries;
use App\Filament\Resources\DocumentSeries\Pages\ListDocumentSeries;
use App\Filament\Resources\DocumentSeries\Pages\ViewDocumentSeries;
use App\Filament\Resources\DocumentSeries\Schemas\DocumentSeriesForm;
use App\Filament\Resources\DocumentSeries\Schemas\DocumentSeriesInfolist;
use App\Filament\Resources\DocumentSeries\Tables\DocumentSeriesTable;
use App\Models\DocumentSeries;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DocumentSeriesResource extends Resource
{
    protected static ?string $model = DocumentSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DocumentSeriesForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DocumentSeriesInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentSeriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentSeries::route('/'),
            'create' => CreateDocumentSeries::route('/create'),
            'view' => ViewDocumentSeries::route('/{record}'),
            'edit' => EditDocumentSeries::route('/{record}/edit'),
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
