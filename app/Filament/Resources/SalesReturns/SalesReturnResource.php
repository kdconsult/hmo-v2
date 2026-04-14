<?php

namespace App\Filament\Resources\SalesReturns;

use App\Enums\NavigationGroup;
use App\Filament\Resources\SalesReturns\Pages\CreateSalesReturn;
use App\Filament\Resources\SalesReturns\Pages\EditSalesReturn;
use App\Filament\Resources\SalesReturns\Pages\ListSalesReturns;
use App\Filament\Resources\SalesReturns\Pages\ViewSalesReturn;
use App\Filament\Resources\SalesReturns\RelationManagers\SalesReturnItemsRelationManager;
use App\Filament\Resources\SalesReturns\Schemas\SalesReturnForm;
use App\Filament\Resources\SalesReturns\Tables\SalesReturnsTable;
use App\Models\SalesReturn;
use Filament\Resources\Resource;
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
