<?php

namespace App\Filament\Resources\CustomerInvoices;

use App\Enums\NavigationGroup;
use App\Filament\Resources\CustomerInvoices\Pages\CreateCustomerInvoice;
use App\Filament\Resources\CustomerInvoices\Pages\EditCustomerInvoice;
use App\Filament\Resources\CustomerInvoices\Pages\ListCustomerInvoices;
use App\Filament\Resources\CustomerInvoices\Pages\ViewCustomerInvoice;
use App\Filament\Resources\CustomerInvoices\RelationManagers\CustomerInvoiceItemsRelationManager;
use App\Filament\Resources\CustomerInvoices\Schemas\CustomerInvoiceForm;
use App\Filament\Resources\CustomerInvoices\Tables\CustomerInvoicesTable;
use App\Models\CustomerInvoice;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerInvoiceResource extends Resource
{
    protected static ?string $model = CustomerInvoice::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function form(Schema $schema): Schema
    {
        return CustomerInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CustomerInvoiceItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerInvoices::route('/'),
            'create' => CreateCustomerInvoice::route('/create'),
            'view' => ViewCustomerInvoice::route('/{record}'),
            'edit' => EditCustomerInvoice::route('/{record}/edit'),
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
