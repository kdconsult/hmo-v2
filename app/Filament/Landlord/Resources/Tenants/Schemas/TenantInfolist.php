<?php

namespace App\Filament\Landlord\Resources\Tenants\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('IDasdasdasd'),
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('email')
                    ->label('Email address')
                    ->placeholder('-'),
                TextEntry::make('phone')
                    ->placeholder('-'),
                TextEntry::make('address_line_1')
                    ->placeholder('-'),
                TextEntry::make('city')
                    ->placeholder('-'),
                TextEntry::make('postal_code')
                    ->placeholder('-'),
                TextEntry::make('country_code'),
                TextEntry::make('vat_number')
                    ->placeholder('-'),
                TextEntry::make('eik')
                    ->placeholder('-'),
                TextEntry::make('mol')
                    ->placeholder('-'),
                TextEntry::make('logo_path')
                    ->placeholder('-'),
                TextEntry::make('locale'),
                TextEntry::make('timezone'),
                TextEntry::make('default_currency_code'),
                TextEntry::make('subscription_plan')
                    ->placeholder('-'),
                TextEntry::make('subscription_ends_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),

                Section::make('Lifecycle')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('deactivation_reason')
                            ->placeholder('-'),
                        TextEntry::make('deactivated_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('deactivatedBy.name')
                            ->label('Deactivated By')
                            ->placeholder('-'),
                        TextEntry::make('marked_for_deletion_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('scheduled_for_deletion_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('deletion_scheduled_for')
                            ->label('Will Be Deleted On')
                            ->dateTime()
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
