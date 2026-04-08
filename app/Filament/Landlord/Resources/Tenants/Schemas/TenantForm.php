<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Tenants\Schemas;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\User;
use App\Support\EuCountries;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
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
                            ->helperText('Used as the subdomain: slug.'.last(config('tenancy.central_domains')))
                            ->alphaDash()
                            ->maxLength(63)
                            ->visibleOn('edit'),

                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50),

                        TextInput::make('eik')
                            ->label('EIK / Company Number')
                            ->maxLength(20),

                        TextInput::make('vat_number')
                            ->label('VAT Number')
                            ->maxLength(20),

                        TextInput::make('mol')
                            ->label('Responsible Person (MOL)')
                            ->maxLength(255),

                        TextInput::make('address_line_1')
                            ->label('Address')
                            ->maxLength(255),

                        TextInput::make('city')
                            ->maxLength(100),

                        TextInput::make('postal_code')
                            ->maxLength(20),

                        Select::make('country_code')
                            ->label('Country')
                            ->options(EuCountries::forSelect())
                            ->required()
                            ->default('BG')
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set) {
                                if ($state === null) {
                                    return;
                                }

                                $country = EuCountries::get($state);
                                if ($country === null) {
                                    return;
                                }

                                $set('default_currency_code', $country['currency_code']);
                                $set('timezone', $country['timezone']);
                                $set('locale', $country['locale']);
                            }),
                    ]),

                Section::make('Localization')
                    ->columns(2)
                    ->schema([
                        Select::make('locale')
                            ->options(
                                collect(EuCountries::all())
                                    ->mapWithKeys(fn (array $c) => [$c['locale'] => $c['locale']])
                                    ->sort()
                                    ->all()
                            )
                            ->required()
                            ->default('bg_BG')
                            ->searchable(),

                        Select::make('timezone')
                            ->options(
                                collect(EuCountries::timezones())
                                    ->mapWithKeys(fn (string $tz) => [$tz => $tz])
                                    ->sort()
                                    ->all()
                            )
                            ->required()
                            ->default('Europe/Sofia')
                            ->searchable(),

                        Select::make('default_currency_code')
                            ->label('Default Currency')
                            ->options([
                                'EUR' => 'Euro (EUR)',
                                'CZK' => 'Czech Koruna (CZK)',
                                'DKK' => 'Danish Krone (DKK)',
                                'HUF' => 'Hungarian Forint (HUF)',
                                'PLN' => 'Polish Zloty (PLN)',
                                'RON' => 'Romanian Leu (RON)',
                                'SEK' => 'Swedish Krona (SEK)',
                                'GBP' => 'British Pound (GBP)',
                                'USD' => 'US Dollar (USD)',
                            ])
                            ->required()
                            ->default('EUR')
                            ->searchable(),
                    ]),

                Section::make('Subscription')
                    ->columns(2)
                    ->schema([
                        Select::make('plan_id')
                            ->label('Plan')
                            ->relationship('plan', 'name')
                            ->options(
                                fn () => Plan::where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'id')
                            )
                            ->required()
                            ->default(fn () => Plan::where('slug', 'free')->value('id'))
                            ->searchable(),

                        Select::make('subscription_status')
                            ->options(SubscriptionStatus::class)
                            ->required()
                            ->default(SubscriptionStatus::Trial)
                            ->visibleOn('edit'),

                        DateTimePicker::make('trial_ends_at')
                            ->label('Trial Ends At')
                            ->default(fn () => now()->addDays(14)),

                        DateTimePicker::make('subscription_ends_at')
                            ->label('Paid Subscription Ends At')
                            ->visibleOn('edit'),
                    ]),

                Section::make('Owner')
                    ->description('The user who will manage this tenant\'s account.')
                    ->columns(1)
                    ->visibleOn('create')
                    ->schema([
                        Select::make('owner_user_id')
                            ->label('Owner User')
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Select an existing user or create a new one via Users resource first. Leave empty to create without an owner.'),
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
