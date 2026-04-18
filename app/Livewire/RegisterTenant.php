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
use App\Services\ViesValidationService;
use App\Support\EuCountries;
use App\Support\TenantUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
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

    public string $eik = '';

    // Auto-filled from country
    public string $currency_code = 'EUR';

    public string $timezone = 'Europe/Sofia';

    public string $locale = 'bg_BG';

    // Step 3: VAT Registration (optional)
    public bool $isVatRegistered = false;

    public string $vatLookup = '';

    public string $confirmedVatNumber = '';

    public string $vatCheckMessage = '';

    public string $vatCheckType = '';

    // Step 4: Plan
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

    public function updatedIsVatRegistered(bool $value): void
    {
        if (! $value) {
            $this->vatLookup = '';
            $this->confirmedVatNumber = '';
            $this->vatCheckMessage = '';
            $this->vatCheckType = '';
        }
    }

    public function checkVies(): void
    {
        $this->confirmedVatNumber = '';
        $this->vatCheckMessage = '';
        $this->vatCheckType = '';

        $lookupValue = trim($this->vatLookup);
        if (blank($lookupValue)) {
            $this->vatCheckMessage = 'Enter a VAT number first.';
            $this->vatCheckType = 'warning';

            return;
        }

        $prefix = EuCountries::vatPrefixForCountry($this->country_code) ?? $this->country_code;
        $fullVat = strtoupper($prefix.$lookupValue);

        $regex = EuCountries::vatNumberRegex($this->country_code);
        if ($regex && ! preg_match($regex, $fullVat)) {
            $example = EuCountries::vatNumberExample($this->country_code);
            $this->vatCheckMessage = 'Invalid VAT number format'.($example ? ". Expected: {$example}" : '').'.';
            $this->vatCheckType = 'danger';

            return;
        }

        $result = app(ViesValidationService::class)->validate($prefix, $lookupValue);

        if (! $result['available']) {
            $this->vatCheckMessage = 'VIES is currently unavailable. You can skip this step and verify later in Company Settings.';
            $this->vatCheckType = 'warning';

            return;
        }

        if (! $result['valid']) {
            $this->vatCheckMessage = "VAT number {$fullVat} was not found in VIES. Check the number and try again, or skip this step.";
            $this->vatCheckType = 'danger';

            return;
        }

        $this->confirmedVatNumber = strtoupper($prefix.($result['vat_number'] ?? $lookupValue));
        $this->vatCheckMessage = "Confirmed: {$this->confirmedVatNumber}";
        $this->vatCheckType = 'success';
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

        // Rate-limit submit actions (Livewire posts bypass route-level throttle)
        $throttleKey = 'tenant-registration:'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->addError('email', 'Too many registration attempts. Please try again in a minute.');

            return;
        }

        RateLimiter::hit($throttleKey, 60);

        $slug = Tenant::generateUniqueSlug();

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        // Tenant::create() triggers CREATE DATABASE in PostgreSQL, which cannot
        // run inside a transaction block — writes are not wrapped here.
        $confirmedVat = $this->confirmedVatNumber ?: null;

        $tenant = Tenant::create([
            'name' => $this->company_name,
            'slug' => $slug,
            'email' => $this->email,
            'country_code' => $this->country_code,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'default_currency_code' => $this->currency_code,
            'vat_number' => $confirmedVat,
            'is_vat_registered' => $confirmedVat !== null,
            'vies_verified_at' => $confirmedVat !== null ? now() : null,
            'eik' => $this->eik,
            'plan_id' => $this->plan_id,
            'subscription_status' => SubscriptionStatus::Trial->value,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $tenant->domains()->create(['domain' => $slug]);
        $tenant->users()->attach($user->id);

        // Tenant-DB onboarding and mail
        app(TenantOnboardingService::class)->onboard($tenant, $user);

        Mail::to($user->email)->send(new WelcomeTenant($tenant, $user));

        // Notify landlord
        Mail::to(config('hmo.landlord_email'))->send(new NewTenantRegistered($tenant));
        $notification = new NewTenantRegisteredNotification($tenant);
        User::where('is_landlord', true)->each(fn (User $landlord) => $landlord->notify($notification));

        $this->redirect(TenantUrl::to($slug, 'admin'), navigate: false);
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
                'country_code' => ['required', 'string', 'size:2', Rule::in(EuCountries::codes())],
                'eik' => ['required', 'string', 'max:20', 'unique:tenants,eik'],
            ]),
            3 => null, // VAT step is entirely optional
            4 => $this->validate([
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
