<?php

namespace Database\Factories;

use App\Enums\PartnerType;
use App\Enums\VatStatus;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    public function definition(): array
    {
        return [
            'type' => PartnerType::Company->value,
            'name' => fake()->company(),
            'company_name' => fake()->company(),
            'eik' => fake()->numerify('#########'),
            'vat_number' => null,
            'is_vat_registered' => false,
            'vat_status' => VatStatus::NotRegistered,
            'country_code' => 'BG',
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'is_customer' => true,
            'is_supplier' => false,
            'default_currency_code' => 'EUR',
            'is_active' => true,
        ];
    }

    public function customer(): static
    {
        return $this->state(fn () => ['is_customer' => true, 'is_supplier' => false]);
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['is_customer' => false, 'is_supplier' => true]);
    }

    public function euWithVat(string $countryCode = 'DE'): static
    {
        return $this->state(fn () => [
            'country_code' => $countryCode,
            'vat_number' => $countryCode.'123456789',
            'is_vat_registered' => true,
            'vat_status' => VatStatus::Confirmed,
            'vies_verified_at' => now(),
            'is_customer' => true,
        ]);
    }

    public function euWithoutVat(string $countryCode = 'DE'): static
    {
        return $this->state(fn () => [
            'country_code' => $countryCode,
            'vat_number' => null,
            'is_vat_registered' => false,
            'vat_status' => VatStatus::NotRegistered,
            'is_customer' => true,
        ]);
    }

    public function vatPending(string $countryCode = 'DE'): static
    {
        return $this->state(fn () => [
            'country_code' => $countryCode,
            'vat_number' => null,
            'is_vat_registered' => true,
            'vat_status' => VatStatus::Pending,
            'vies_last_checked_at' => now(),
            'is_customer' => true,
        ]);
    }

    public function nonEu(string $countryCode = 'US'): static
    {
        return $this->state(fn () => [
            'country_code' => $countryCode,
            'vat_number' => null,
            'is_vat_registered' => false,
            'vat_status' => VatStatus::NotRegistered,
            'is_customer' => true,
        ]);
    }
}
