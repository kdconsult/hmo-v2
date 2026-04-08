<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Plans\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('price')
                    ->money('EUR')
                    ->sortable(),

                TextColumn::make('billing_period')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : 'Free')
                    ->color(fn (?string $state) => $state ? 'info' : 'gray'),

                TextColumn::make('max_users')
                    ->label('Max Users')
                    ->formatStateUsing(fn (?int $state) => $state ?? 'Unlimited'),

                TextColumn::make('max_documents')
                    ->label('Max Docs/Mo')
                    ->formatStateUsing(fn (?int $state) => $state ?? 'Unlimited'),

                TextColumn::make('tenants_count')
                    ->label('Tenants')
                    ->counts('tenants')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
