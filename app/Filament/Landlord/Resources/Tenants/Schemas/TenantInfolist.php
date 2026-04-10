<?php

namespace App\Filament\Landlord\Resources\Tenants\Schemas;

use App\Models\Tenant;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Overview')
                    ->icon('heroicon-o-building-office-2')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->columnSpan(2),
                        IconEntry::make('is_landlord_tenant')
                            ->label('Landlord Account')
                            ->state(fn (Tenant $record): bool => $record->isLandlordTenant())
                            ->boolean()
                            ->trueIcon('heroicon-o-star')
                            ->falseIcon('heroicon-o-minus')
                            ->trueColor('warning')
                            ->falseColor('gray'),
                        TextEntry::make('slug')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label('Registered')
                            ->dateTime(),
                    ]),

                Section::make('Contact')
                    ->icon('heroicon-o-envelope')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('email')
                            ->label('Email Address')
                            ->placeholder('—'),
                        TextEntry::make('phone')
                            ->placeholder('—'),
                    ]),

                Section::make('Address')
                    ->icon('heroicon-o-map-pin')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('address_line_1')
                            ->label('Street')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('city')
                            ->placeholder('—'),
                        TextEntry::make('postal_code')
                            ->label('Postal Code')
                            ->placeholder('—'),
                        TextEntry::make('country_code')
                            ->label('Country'),
                    ]),

                Section::make('Company Details')
                    ->icon('heroicon-o-identification')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('eik')
                            ->label('EIK')
                            ->placeholder('—'),
                        TextEntry::make('vat_number')
                            ->label('VAT Number')
                            ->placeholder('—'),
                        TextEntry::make('mol')
                            ->label('MOL')
                            ->placeholder('—'),
                    ]),

                Section::make('Subscription & Plan')
                    ->icon('heroicon-o-credit-card')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('plan.name')
                            ->label('Plan')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('subscription_status')
                            ->label('Subscription Status')
                            ->badge(),
                        TextEntry::make('trial_ends_at')
                            ->label('Trial Ends')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('subscription_ends_at')
                            ->label('Subscription Ends')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),

                Section::make('Regional Settings')
                    ->icon('heroicon-o-globe-alt')
                    ->columns(3)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('locale'),
                        TextEntry::make('timezone'),
                        TextEntry::make('default_currency_code')
                            ->label('Default Currency'),
                    ]),

                Section::make('Deactivation History')
                    ->icon('heroicon-o-clock')
                    ->columns(2)
                    ->visible(fn (Tenant $record): bool => ! $record->isActive())
                    ->schema([
                        TextEntry::make('deactivation_reason')
                            ->label('Reason')
                            ->placeholder('—'),
                        TextEntry::make('deactivated_at')
                            ->label('Deactivated At')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('deactivatedBy.name')
                            ->label('Deactivated By')
                            ->placeholder('—'),
                        TextEntry::make('marked_for_deletion_at')
                            ->label('Marked for Deletion')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('scheduled_for_deletion_at')
                            ->label('Scheduled for Deletion')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('deletion_scheduled_for')
                            ->label('Will Be Deleted On')
                            ->dateTime()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
