<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Tenants\Schemas;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\User;
use App\Support\EuCountries;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Http;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Left column: Company Info
                Section::make('Company Info')
                    ->columnSpan(1)
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
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->belowContent(Schema::end([
                                Icon::make(Heroicon::InformationCircle),
                                'Check against VIES and auto-fill VAT number',
                                Action::make('fetch_eik_details')
                                    ->label('Check VIES')
                                    ->rateLimit(5)
                                    ->action(self::checkVies(...)),
                            ]))
                            ->maxLength(20),

                        TextInput::make('vat_number')
                            ->label('VAT Number')
                            ->maxLength(20)
                            ->helperText(fn (Get $get): ?string => ($example = EuCountries::vatNumberExample($get('country_code') ?? 'BG'))
                                ? "Format: {$example}"
                                : null)
                            ->rules(fn (Get $get): array => [
                                static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (blank($value)) {
                                        return;
                                    }
                                    $regex = EuCountries::vatNumberRegex($get('country_code') ?? 'BG');
                                    if ($regex && ! preg_match($regex, strtoupper((string) $value))) {
                                        $fail('Invalid VAT number format for the selected country.');
                                    }
                                },
                            ]),

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
                                $set('vat_number', null);

                                $country = $state ? EuCountries::get($state) : ['currency_code' => null, 'timezone' => null, 'locale' => null];

                                $set('default_currency_code', $country['currency_code']);
                                $set('timezone', $country['timezone']);
                                $set('locale', $country['locale']);
                            }),
                    ]),

                // Right column: Localization + Subscription stacked
                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Localization')
                            ->columns(3)
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
                    ]),

                // Full width: Owner (create only)
                Section::make('Owner')
                    ->description('The user who will manage this tenant\'s account.')
                    ->columnSpanFull()
                    ->visibleOn('create')
                    ->schema([
                        Select::make('owner_user_id')
                            ->label('Owner User')
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Select an existing user or create a new one via Users resource first. Leave empty to create without an owner.'),
                    ]),

                // Full-width: Lifecycle Status (edit only)
                Section::make('Lifecycle Status')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->state(fn ($record) => $record?->status?->getLabel() ?? '-'),
                        TextEntry::make('deactivation_reason')
                            ->label('Deactivation Reason')
                            ->state(fn ($record) => match ($record?->deactivation_reason) {
                                'non_payment' => 'Non-payment',
                                'tenant_request' => 'Tenant request',
                                'other' => 'Other',
                                default => '-',
                            }),
                        TextEntry::make('deactivated_at')
                            ->label('Deactivated At')
                            ->state(fn ($record) => $record?->deactivated_at?->toDateTimeString() ?? '-'),
                        TextEntry::make('deactivated_by_name')
                            ->label('Deactivated By')
                            ->state(fn ($record) => $record?->deactivatedBy?->name ?? '-'),
                        TextEntry::make('marked_for_deletion_at')
                            ->label('Marked for Deletion At')
                            ->state(fn ($record) => $record?->marked_for_deletion_at?->toDateTimeString() ?? '-'),
                        TextEntry::make('scheduled_for_deletion_at')
                            ->label('Scheduled for Deletion At')
                            ->state(fn ($record) => $record?->scheduled_for_deletion_at?->toDateTimeString() ?? '-'),
                        TextEntry::make('deletion_scheduled_for')
                            ->label('Will Be Deleted On')
                            ->state(fn ($record) => $record?->deletion_scheduled_for?->toDateTimeString() ?? '-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function checkVies(Get $get, Set $set): void
    {
        $eik = $get('eik');
        $countryCode = $get('country_code') ?? 'BG';

        if (blank($eik)) {
            Notification::make()->warning()->title('Enter an EIK first')->send();

            return;
        }

        $vatPrefix = preg_replace('/[^A-Za-z]/', '', EuCountries::vatPrefixForCountry($countryCode) ?? $countryCode);

        // For countries like Bulgaria, branch/subdivision EIKs have extra suffix digits
        // that are not part of the VAT number — extract only the main company identifier.
        $vatNumber = preg_replace('/[^A-Za-z0-9]/', '', EuCountries::extractMainVatNumber($countryCode, $eik));

        $response = Http::timeout(10)
            ->get("https://ec.europa.eu/taxation_customs/vies/rest-api/ms/{$vatPrefix}/vat/{$vatNumber}");

        if ($response->failed()) {
            Notification::make()->danger()->title('VIES service unavailable')->send();

            return;
        }

        $data = $response->json();

        if (! ($data['isValid'] ?? false)) {
            $set('vat_number', null);
            Notification::make()->warning()
                ->title('No active VAT registration found')
                ->body("Checked: {$vatPrefix}{$vatNumber}")
                ->send();

            return;
        }

        // VIES returns the canonical vatNumber in the response; prefer that over our derived value.
        $confirmedVat = $vatPrefix.($data['vatNumber'] ?? $vatNumber);
        $set('vat_number', $confirmedVat);

        $name = $data['name'] ?? null;
        if (filled($name) && $name !== '---') {
            $set('name', $name);
        }

        Notification::make()->success()
            ->title('VAT registration confirmed')
            ->body($confirmedVat)
            ->send();
    }
}
