<?php

namespace App\Filament\Resources\TenantUsers;

use App\Enums\NavigationGroup;
use App\Filament\Resources\TenantUsers\Pages\CreateTenantUser;
use App\Filament\Resources\TenantUsers\Pages\EditTenantUser;
use App\Filament\Resources\TenantUsers\Pages\ListTenantUsers;
use App\Filament\Resources\TenantUsers\Pages\ViewTenantUser;
use App\Filament\Resources\TenantUsers\Schemas\TenantUserForm;
use App\Filament\Resources\TenantUsers\Schemas\TenantUserInfolist;
use App\Filament\Resources\TenantUsers\Tables\TenantUsersTable;
use App\Models\TenantUser;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantUserResource extends Resource
{
    protected static ?string $model = TenantUser::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?string $recordTitleAttribute = 'user_id';

    public static function form(Schema $schema): Schema
    {
        return TenantUserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TenantUserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantUsersTable::configure($table);
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
            'index' => ListTenantUsers::route('/'),
            'create' => CreateTenantUser::route('/create'),
            'view' => ViewTenantUser::route('/{record}'),
            'edit' => EditTenantUser::route('/{record}/edit'),
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
