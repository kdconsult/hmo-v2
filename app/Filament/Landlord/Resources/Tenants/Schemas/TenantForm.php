<?php

namespace App\Filament\Landlord\Resources\Tenants\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company Info')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Used as the subdomain: slug.hmo.bg')
                            ->alphaDash()
                            ->maxLength(63),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('eik')
                            ->label('EIK')
                            ->maxLength(20),
                        TextInput::make('vat_number')
                            ->label('VAT Number')
                            ->maxLength(20),
                        TextInput::make('mol')
                            ->label('MOL')
                            ->maxLength(255),
                        TextInput::make('address_line_1')
                            ->label('Address')
                            ->maxLength(255),
                        TextInput::make('city')
                            ->maxLength(100),
                        TextInput::make('postal_code')
                            ->maxLength(20),
                        TextInput::make('country_code')
                            ->required()
                            ->default('BG')
                            ->maxLength(2),
                    ]),

                Section::make('Localization')
                    ->columns(2)
                    ->schema([
                        TextInput::make('locale')
                            ->required()
                            ->default('bg')
                            ->maxLength(5),
                        TextInput::make('timezone')
                            ->required()
                            ->default('Europe/Sofia')
                            ->maxLength(100),
                        TextInput::make('default_currency_code')
                            ->label('Default Currency')
                            ->required()
                            ->default('BGN')
                            ->maxLength(3),
                    ]),

                Section::make('Subscription')
                    ->columns(2)
                    ->schema([
                        TextInput::make('subscription_plan')
                            ->maxLength(50),
                        DateTimePicker::make('subscription_ends_at')
                            ->label('Subscription Ends At'),
                    ]),

                Section::make('Lifecycle Status')
                    ->columns(2)
                    ->visibleOn('edit')
                    ->schema([
                        Placeholder::make('status')
                            ->content(fn ($record) => $record?->status?->getLabel() ?? '-'),
                        Placeholder::make('deactivation_reason')
                            ->label('Deactivation Reason')
                            ->content(fn ($record) => match ($record?->deactivation_reason) {
                                'non_payment' => 'Non-payment',
                                'tenant_request' => 'Tenant request',
                                'other' => 'Other',
                                default => '-',
                            }),
                        Placeholder::make('deactivated_at')
                            ->label('Deactivated At')
                            ->content(fn ($record) => $record?->deactivated_at?->toDateTimeString() ?? '-'),
                        Placeholder::make('deactivated_by_name')
                            ->label('Deactivated By')
                            ->content(fn ($record) => $record?->deactivatedBy?->name ?? '-'),
                        Placeholder::make('marked_for_deletion_at')
                            ->label('Marked for Deletion At')
                            ->content(fn ($record) => $record?->marked_for_deletion_at?->toDateTimeString() ?? '-'),
                        Placeholder::make('scheduled_for_deletion_at')
                            ->label('Scheduled for Deletion At')
                            ->content(fn ($record) => $record?->scheduled_for_deletion_at?->toDateTimeString() ?? '-'),
                        Placeholder::make('deletion_scheduled_for')
                            ->label('Will Be Deleted On')
                            ->content(fn ($record) => $record?->deletion_scheduled_for?->toDateTimeString() ?? '-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
