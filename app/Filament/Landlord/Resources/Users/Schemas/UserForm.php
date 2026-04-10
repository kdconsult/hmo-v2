<?php

namespace App\Filament\Landlord\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                DateTimePicker::make('email_verified_at')
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state)),
                TextInput::make('avatar_path'),
                TextInput::make('locale'),
                Toggle::make('is_landlord')
                    ->required()
                    ->disabled(fn (?User $record): bool => $record?->id === auth()->id())
                    ->helperText('Warning: grants full landlord panel access.'),
                DateTimePicker::make('last_login_at')
                    ->disabled(),
            ]);
    }
}
