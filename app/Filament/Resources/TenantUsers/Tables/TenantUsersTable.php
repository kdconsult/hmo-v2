<?php

namespace App\Filament\Resources\TenantUsers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TenantUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user_name')
                    ->label('Name')
                    ->state(fn ($record) => $record->centralUser()?->name ?? "User #{$record->user_id}")
                    ->searchable(query: fn ($query, string $search) => $query->where('user_id', 'like', "%{$search}%"))
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('user_id', $direction)),

                TextColumn::make('user_email')
                    ->label('Email')
                    ->state(fn ($record) => $record->centralUser()?->email ?? '-'),

                TextColumn::make('roles')
                    ->label('Role')
                    ->state(fn ($record) => $record->getRoleNames()->implode(', ') ?: '-')
                    ->badge(),

                TextColumn::make('job_title')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
