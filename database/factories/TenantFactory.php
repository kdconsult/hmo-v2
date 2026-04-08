<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'id' => Str::uuid()->toString(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'country_code' => 'BG',
            'vat_number' => null,
            'eik' => fake()->numerify('#########'),
            'mol' => fake()->name(),
            'logo_path' => null,
            'locale' => 'bg',
            'timezone' => 'Europe/Sofia',
            'default_currency_code' => 'BGN',
            'subscription_plan' => null,
            'subscription_ends_at' => null,
        ];
    }
}
