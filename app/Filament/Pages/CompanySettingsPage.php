<?php

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Models\CompanySettings;
use App\Models\Currency;
use App\Services\CompanyVatService;
use App\Services\ViesValidationService;
use App\Support\EuCountries;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CompanySettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.company-settings-page';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?string $navigationLabel = 'Company Settings';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', CompanySettings::class) ?? false;
    }

    public array $data = [];

    public array $general = [];

    public array $invoicing = [];

    public array $purchasing = [];

    public array $fiscal = [];

    public function mount(): void
    {
        $tenant = tenancy()->tenant;

        $localizationGroup = CompanySettings::getGroup('localization');

        foreach (['en', 'bg', 'de', 'fr', 'es', 'ro', 'tr', 'el', 'sr'] as $code) {
            $key = "locale_{$code}";
            $localizationGroup[$key] = array_key_exists($key, $localizationGroup)
                ? ! empty($localizationGroup[$key])
                : $code === 'en';
        }

        $companyGroup = CompanySettings::getGroup('company');
        if (empty($companyGroup['country_code'])) {
            $companyGroup['country_code'] = $tenant->country_code;
        }

        $this->form->fill([
            'general' => CompanySettings::getGroup('general'),
            'company' => $companyGroup,
            'invoicing' => CompanySettings::getGroup('invoicing'),
            'purchasing' => CompanySettings::getGroup('purchasing'),
            'catalog' => CompanySettings::getGroup('catalog'),
            'fiscal' => CompanySettings::getGroup('fiscal'),
            'localization' => $localizationGroup,
            'vat' => [
                'is_vat_registered' => $tenant->is_vat_registered,
                'vat_number' => $tenant->vat_number,
                'vat_lookup' => '',
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()
                    ->tabs([
                        Tab::make('General')
                            ->schema([
                                TextInput::make('general.company_name')
                                    ->label('Company Name'),
                                TextInput::make('general.company_email')
                                    ->label('Company Email')
                                    ->email(),
                                TextInput::make('general.company_phone')
                                    ->label('Phone'),
                                Select::make('company.country_code')
                                    ->label('Company Country')
                                    ->options(EuCountries::forSelect())
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->helperText('Used for EU VAT determination (domestic vs reverse charge vs OSS). Required.')
                                    ->afterStateUpdated(fn () => $this->resetVatState()),
                                Select::make('general.default_currency')
                                    ->label('Default Currency')
                                    ->options(Currency::active()->orderBy('name')->pluck('name', 'code'))
                                    ->searchable()
                                    ->default('EUR'),
                                Select::make('general.locale')
                                    ->label('Language')
                                    ->options([
                                        'bg' => 'Български',
                                        'en' => 'English',
                                    ])
                                    ->default('bg'),

                                Section::make('VAT Registration')
                                    ->schema([
                                        Toggle::make('vat.is_vat_registered')
                                            ->label('Company is VAT Registered')
                                            ->live()
                                            ->inline(false)
                                            ->afterStateUpdated(function (bool $state): void {
                                                if (! $state) {
                                                    data_set($this->data, 'vat.vat_number', null);
                                                    data_set($this->data, 'vat.vat_lookup', '');
                                                }
                                            }),

                                        TextInput::make('vat.vat_lookup')
                                            ->label('VAT Number (without country prefix)')
                                            ->prefix(fn (): string => $this->vatCountryPrefix())
                                            ->visible(fn (Get $get): bool => (bool) $get('vat.is_vat_registered'))
                                            ->helperText(fn (): ?string => $this->vatLookupHelperText())
                                            ->suffixAction(
                                                Action::make('check_vies')
                                                    ->label('Check VIES')
                                                    ->icon(Heroicon::Bolt)
                                                    ->action('handleViesCheck')
                                            ),

                                        TextInput::make('vat.vat_number')
                                            ->label('Confirmed VAT Number')
                                            ->disabled()
                                            ->visible(fn (Get $get): bool => filled($get('vat.vat_number'))),
                                    ]),
                            ]),

                        Tab::make('Invoicing')
                            ->schema([
                                TextInput::make('invoicing.invoice_prefix')
                                    ->label('Invoice Prefix')
                                    ->default('INV'),
                                TextInput::make('invoicing.payment_terms_days')
                                    ->label('Default Payment Terms (days)')
                                    ->numeric()
                                    ->default(30),
                                TextInput::make('invoicing.bank_iban')
                                    ->label('Bank IBAN'),
                                TextInput::make('invoicing.bank_name')
                                    ->label('Bank Name'),
                                Toggle::make('invoicing.auto_send_invoices')
                                    ->label('Auto-send Invoices'),
                            ]),

                        Tab::make('Purchasing')
                            ->schema([
                                Toggle::make('purchasing.express_purchasing')
                                    ->label('Express Purchasing')
                                    ->helperText('When enabled, a "Confirm & Receive" action appears on supplier invoices, allowing one-click invoice confirmation with automatic goods receipt.')
                                    ->inline(false),
                            ]),

                        Tab::make('Catalog')
                            ->schema([
                                Toggle::make('catalog.require_product_category')
                                    ->label('Require Product Category')
                                    ->helperText('When enabled, every product must be assigned a category.')
                                    ->inline(false),
                            ]),

                        Tab::make('Fiscal')
                            ->schema([
                                TextInput::make('fiscal.fiscal_device_ip')
                                    ->label('Fiscal Device IP'),
                                TextInput::make('fiscal.fiscal_device_port')
                                    ->label('Port')
                                    ->default('8090'),
                                Toggle::make('fiscal.fiscal_enabled')
                                    ->label('Fiscal Printing Enabled'),
                            ]),

                        Tab::make('Localization')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        Toggle::make('localization.locale_en')->label('English')->inline(false),
                                        Toggle::make('localization.locale_bg')->label('Български')->inline(false),
                                        Toggle::make('localization.locale_de')->label('Deutsch')->inline(false),
                                        Toggle::make('localization.locale_fr')->label('Français')->inline(false),
                                        Toggle::make('localization.locale_es')->label('Español')->inline(false),
                                        Toggle::make('localization.locale_ro')->label('Română')->inline(false),
                                        Toggle::make('localization.locale_tr')->label('Türkçe')->inline(false),
                                        Toggle::make('localization.locale_el')->label('Ελληνικά')->inline(false),
                                        Toggle::make('localization.locale_sr')->label('Српски')->inline(false),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public function resetVatState(): void
    {
        data_set($this->data, 'vat.is_vat_registered', false);
        data_set($this->data, 'vat.vat_number', null);
        data_set($this->data, 'vat.vat_lookup', '');
    }

    public function handleViesCheck(): void
    {
        $countryCode = data_get($this->data, 'company.country_code', 'BG');
        $lookupValue = trim((string) data_get($this->data, 'vat.vat_lookup', ''));

        if (blank($lookupValue)) {
            Notification::make()->warning()->title('Enter a VAT number first')->send();

            return;
        }

        $prefix = EuCountries::vatPrefixForCountry($countryCode) ?? $countryCode;
        $fullVat = strtoupper($prefix.$lookupValue);

        $regex = EuCountries::vatNumberRegex($countryCode);
        if ($regex && ! preg_match($regex, $fullVat)) {
            Notification::make()->danger()
                ->title('Invalid VAT number format')
                ->body('Expected format: '.(EuCountries::vatNumberExample($countryCode) ?? 'unknown'))
                ->send();

            return;
        }

        $result = app(ViesValidationService::class)->validate($prefix, $lookupValue);

        if (! $result['available']) {
            // Principle 4: VIES unavailable = invalid — reset toggle, clear VAT field
            data_set($this->data, 'vat.is_vat_registered', false);
            data_set($this->data, 'vat.vat_number', null);
            Notification::make()->danger()
                ->title('VIES service is unreachable')
                ->body('Please try again later.')
                ->send();

            return;
        }

        if (! $result['valid']) {
            data_set($this->data, 'vat.is_vat_registered', false);
            data_set($this->data, 'vat.vat_number', null);
            Notification::make()->warning()
                ->title('VAT number not found in VIES')
                ->body("Checked: {$fullVat}")
                ->send();

            return;
        }

        $confirmedVat = strtoupper($prefix.($result['vat_number'] ?? $lookupValue));
        data_set($this->data, 'vat.vat_number', $confirmedVat);
        data_set($this->data, 'vat.is_vat_registered', true);

        if (filled($result['name'])) {
            data_set($this->data, 'general.company_name', $result['name']);
        }

        Notification::make()->success()
            ->title('VAT registration confirmed')
            ->body($confirmedVat)
            ->send();
    }

    public function save(): void
    {
        $this->authorize('update', CompanySettings::class);

        $vatData = $this->data['vat'] ?? [];

        if (($vatData['is_vat_registered'] ?? false) && blank($vatData['vat_number'] ?? null)) {
            Notification::make()->danger()
                ->title('VAT verification required')
                ->body('Verify your VAT number via VIES before saving.')
                ->send();

            return;
        }

        $tenant = tenancy()->tenant;
        app(CompanyVatService::class)->updateVatRegistration($tenant, [
            'is_vat_registered' => (bool) ($vatData['is_vat_registered'] ?? false),
            'vat_number' => $vatData['vat_number'] ?? null,
            'country_code' => data_get($this->data, 'company.country_code', $tenant->country_code),
        ]);

        foreach ($this->data as $group => $settings) {
            if ($group === 'vat') {
                continue;
            }
            if (is_array($settings)) {
                foreach ($settings as $key => $value) {
                    CompanySettings::set($group, $key, is_array($value) ? json_encode($value) : ($value ?? ''));
                }
            }
        }

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Settings')
                ->action('save'),
        ];
    }

    private function vatCountryPrefix(): string
    {
        $countryCode = data_get($this->data, 'company.country_code', 'BG');

        return EuCountries::vatPrefixForCountry($countryCode) ?? $countryCode;
    }

    private function vatLookupHelperText(): ?string
    {
        $countryCode = data_get($this->data, 'company.country_code', 'BG');
        $example = EuCountries::vatNumberExample($countryCode);

        return $example ? "Format: {$example}" : null;
    }
}
