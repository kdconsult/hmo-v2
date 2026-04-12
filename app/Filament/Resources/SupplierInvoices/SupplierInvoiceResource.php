<?php

namespace App\Filament\Resources\SupplierInvoices;

use App\Enums\NavigationGroup;
use App\Filament\Resources\SupplierInvoices\Pages\CreateSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\Pages\EditSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\Pages\ListSupplierInvoices;
use App\Filament\Resources\SupplierInvoices\Pages\ViewSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\RelationManagers\SupplierInvoiceItemsRelationManager;
use App\Filament\Resources\SupplierInvoices\Schemas\SupplierInvoiceForm;
use App\Filament\Resources\SupplierInvoices\Tables\SupplierInvoicesTable;
use App\Models\SupplierInvoice;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierInvoiceResource extends Resource
{
    protected static ?string $model = SupplierInvoice::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Purchases;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'internal_number';

    public static function form(Schema $schema): Schema
    {
        return SupplierInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SupplierInvoiceItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierInvoices::route('/'),
            'create' => CreateSupplierInvoice::route('/create'),
            'view' => ViewSupplierInvoice::route('/{record}'),
            'edit' => EditSupplierInvoice::route('/{record}/edit'),
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
