<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantUsers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantUserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Account')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('central_name')
                            ->label('Name')
                            ->state(fn ($record) => $record->centralUser()?->name ?? "User #{$record->user_id}"),

                        TextEntry::make('central_email')
                            ->label('Email')
                            ->state(fn ($record) => $record->centralUser()?->email ?? '-'),

                        TextEntry::make('roles')
                            ->label('Role')
                            ->state(fn ($record) => $record->getRoleNames()->implode(', ') ?: 'No role')
                            ->badge(),

                        TextEntry::make('job_title')
                            ->placeholder('Not set'),

                        TextEntry::make('display_name')
                            ->placeholder('Not set'),

                        TextEntry::make('phone')
                            ->placeholder('Not set'),

                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),

                        TextEntry::make('central_last_login')
                            ->label('Last Login')
                            ->state(fn ($record) => $record->centralUser()?->last_login_at?->diffForHumans() ?? 'Never'),

                        TextEntry::make('created_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
