<?php

namespace Database\Factories;

use App\Enums\PricingMode;
use App\Enums\QuotationStatus;
use App\Models\Partner;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        return [
            'quotation_number' => 'QT-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'partner_id' => Partner::factory()->customer(),
            'status' => QuotationStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'valid_until' => null,
            'issued_at' => null,
            'notes' => null,
            'internal_notes' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => QuotationStatus::Draft]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => QuotationStatus::Sent,
            'issued_at' => now()->toDateString(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => QuotationStatus::Accepted,
            'issued_at' => now()->toDateString(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => QuotationStatus::Expired,
            'issued_at' => now()->subDays(30)->toDateString(),
            'valid_until' => now()->subDays(1)->toDateString(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => QuotationStatus::Rejected]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => QuotationStatus::Cancelled]);
    }
}
