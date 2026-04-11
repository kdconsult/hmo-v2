<?php

namespace App\Filament\Pages;

use App\Models\CompanySettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CompanySettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.company-settings-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Company Settings';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', CompanySettings::class) ?? false;
    }

    public array $general = [];

    public array $invoicing = [];

    public array $fiscal = [];

    public function mount(): void
    {
        $this->form->fill([
            'general' => CompanySettings::getGroup('general'),
            'invoicing' => CompanySettings::getGroup('invoicing'),
            'fiscal' => CompanySettings::getGroup('fiscal'),
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
                                TextInput::make('general.default_currency')
                                    ->label('Default Currency')
                                    ->default('BGN'),
                                Select::make('general.locale')
                                    ->label('Language')
                                    ->options([
                                        'bg' => 'Български',
                                        'en' => 'English',
                                    ])
                                    ->default('bg'),
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
                    ]),
            ]);
    }

    public function save(): void
    {
        $this->authorize('update', CompanySettings::class);

        $data = $this->form->getState();

        foreach ($data as $group => $settings) {
            if (is_array($settings)) {
                foreach ($settings as $key => $value) {
                    CompanySettings::set($group, $key, $value ?? '');
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
}
