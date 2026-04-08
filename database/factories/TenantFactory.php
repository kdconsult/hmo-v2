<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
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
            'locale' => 'bg_BG',
            'timezone' => 'Europe/Sofia',
            'default_currency_code' => 'EUR',
            'plan_id' => null,
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(14),
            'subscription_ends_at' => null,
            'status' => TenantStatus::Active,
            'deactivated_at' => null,
            'marked_for_deletion_at' => null,
            'scheduled_for_deletion_at' => null,
            'deletion_scheduled_for' => null,
            'deactivation_reason' => null,
            'deactivated_by' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => TenantStatus::Suspended,
            'deactivated_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'deactivation_reason' => 'non_payment',
        ]);
    }

    public function markedForDeletion(): static
    {
        return $this->suspended()->state(fn () => [
            'status' => TenantStatus::MarkedForDeletion,
            'marked_for_deletion_at' => now()->subDays(fake()->numberBetween(1, 15)),
        ]);
    }

    public function scheduledForDeletion(): static
    {
        return $this->markedForDeletion()->state(fn () => [
            'status' => TenantStatus::ScheduledForDeletion,
            'scheduled_for_deletion_at' => now()->subDays(fake()->numberBetween(1, 10)),
            'deletion_scheduled_for' => now()->addDays(30),
        ]);
    }
}
