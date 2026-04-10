<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Tenants\RelationManagers;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\TenantOnboardingService;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),

                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->maxLength(255),

                Toggle::make('is_landlord')
                    ->default(false)
                    ->disabled()
                    ->helperText('Manage via Users resource.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable(),

                TextColumn::make('last_login_at')
                    ->label('Last Login')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),

                IconColumn::make('is_landlord')
                    ->label('Landlord')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isLandlordTenant())
                    ->after(function ($record) {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();
                        app(TenantOnboardingService::class)->onboard($tenant, $record);
                    }),
                AssociateAction::make()
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isLandlordTenant())
                    ->after(function ($record) {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();

                        // Create TenantUser in tenant DB if it doesn't exist
                        $tenant->run(function () use ($record) {
                            TenantUser::firstOrCreate(['user_id' => $record->id]);
                        });
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
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
