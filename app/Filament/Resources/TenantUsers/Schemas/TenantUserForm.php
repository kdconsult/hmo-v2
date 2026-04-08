<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantUsers\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class TenantUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Account')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('Central User')
                            ->options(fn () => User::on('central')->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->helperText('Select the central user account to link to this tenant.'),

                        Select::make('roles')
                            ->label('Role')
                            ->options(Role::pluck('name', 'name'))
                            ->preload(),

                        TextInput::make('display_name')
                            ->maxLength(255)
                            ->placeholder('Leave empty to use account name'),

                        TextInput::make('job_title')
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50),

                        Toggle::make('is_active')
                            ->default(true),
                    ]),
            ]);
    }
}
