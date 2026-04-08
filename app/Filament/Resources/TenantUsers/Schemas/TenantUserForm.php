<?php

namespace App\Filament\Resources\TenantUsers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class TenantUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('user_id')
                    ->label('User ID')
                    ->required()
                    ->numeric()
                    ->helperText('Central user ID'),
                Select::make('roles')
                    ->label('Roles')
                    ->multiple()
                    ->options(Role::pluck('name', 'name'))
                    ->preload(),
            ]);
    }
}
