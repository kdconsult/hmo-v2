<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\SubscriptionStatus;
use App\Mail\NewTenantRegistered;
use App\Mail\WelcomeTenant;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewTenantRegisteredNotification;
use App\Services\TenantOnboardingService;
use App\Support\EuCountries;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Component;

class RegisterTenant extends Component
{
    public int $step = 1;

    // Step 1: Account
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    // Step 2: Organization
    public string $company_name = '';

    public string $country_code = 'BG';

    public string $vat_number = '';

    public string $eik = '';

    // Auto-filled from country
    public string $currency_code = 'EUR';

    public string $timezone = 'Europe/Sofia';

    public string $locale = 'bg_BG';

    // Step 3: Plan
    public ?int $plan_id = null;

    public function mount(): void
    {
        // Pre-select free plan
        $this->plan_id = Plan::where('slug', 'free')->value('id');
    }

    public function updatedCountryCode(string $value): void
    {
        $country = EuCountries::get($value);
        if ($country) {
            $this->currency_code = $country['currency_code'];
            $this->timezone = $country['timezone'];
            $this->locale = $country['locale'];
        }
    }

    public function nextStep(): void
    {
        $this->validateCurrentStep();
        $this->step++;
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function submit(): void
    {
        $this->validateCurrentStep();

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        $appDomain = last(config('tenancy.central_domains'));
        $slug = Tenant::generateUniqueSlug();

        $tenant = Tenant::create([
            'name' => $this->company_name,
            'slug' => $slug,
            'email' => $this->email,
            'country_code' => $this->country_code,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'default_currency_code' => $this->currency_code,
            'vat_number' => $this->vat_number ?: null,
            'eik' => $this->eik ?: null,
            'plan_id' => $this->plan_id,
            'subscription_status' => SubscriptionStatus::Trial->value,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $tenant->domains()->create([
            'domain' => $slug,
        ]);

        $tenant->users()->attach($user->id);

        app(TenantOnboardingService::class)->onboard($tenant, $user);

        Mail::to($user->email)->send(new WelcomeTenant($tenant, $user));

        // Notify landlord
        Mail::to(config('hmo.landlord_email'))->send(new NewTenantRegistered($tenant));
        $notification = new NewTenantRegisteredNotification($tenant);
        User::where('is_landlord', true)->each(fn (User $landlord) => $landlord->notify($notification));

        $this->redirect("http://{$slug}.{$appDomain}/admin", navigate: false);
    }

    private function validateCurrentStep(): void
    {
        match ($this->step) {
            1 => $this->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]),
            2 => $this->validate([
                'company_name' => ['required', 'string', 'max:255'],
                'country_code' => ['required', 'string', 'size:2'],
            ]),
            3 => $this->validate([
                'plan_id' => ['required', 'exists:plans,id'],
            ]),
            default => null,
        };
    }

    #[Computed]
    public function plans(): Collection
    {
        return Plan::where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function countries(): array
    {
        return EuCountries::forSelect();
    }

    public function render(): View
    {
        return view('livewire.register-tenant')
            ->layout('components.layouts.guest');
    }
}
