<?php

namespace App\Filament\Landlord\Resources\Tenants\RelationManagers;

use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DomainsRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('domain')
                    ->label('Subdomain')
                    ->required()
                    ->maxLength(63)
                    ->alphaDash()
                    ->helperText('Enter only the subdomain (e.g. "acme"), not the full domain.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('domain')
            ->columns([
                TextColumn::make('domain')
                    ->label('Subdomain')
                    ->formatStateUsing(fn (string $state): string => "{$state}.".config('app.domain'))
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isLandlordTenant()),
                AssociateAction::make()
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isLandlordTenant()),
            ])
            ->recordActions([
                EditAction::make(),
                DissociateAction::make()
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isLandlordTenant()),
                DeleteAction::make()
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isLandlordTenant()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
