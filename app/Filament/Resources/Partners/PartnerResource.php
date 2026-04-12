<?php

namespace App\Filament\Resources\Partners;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Partners\Pages\CreatePartner;
use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Filament\Resources\Partners\Pages\ViewPartner;
use App\Filament\Resources\Partners\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Partners\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\Partners\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Partners\Schemas\PartnerForm;
use App\Filament\Resources\Partners\Schemas\PartnerInfolist;
use App\Filament\Resources\Partners\Tables\PartnersTable;
use App\Models\Partner;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Crm;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PartnerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PartnerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartnersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AddressesRelationManager::class,
            ContactsRelationManager::class,
            BankAccountsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartners::route('/'),
            'create' => CreatePartner::route('/create'),
            'view' => ViewPartner::route('/{record}'),
            'edit' => EditPartner::route('/{record}/edit'),
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
