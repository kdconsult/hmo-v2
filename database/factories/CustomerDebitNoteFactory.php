<?php

namespace Database\Factories;

use App\Enums\DebitNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\CustomerDebitNote;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerDebitNote>
 */
class CustomerDebitNoteFactory extends Factory
{
    protected $model = CustomerDebitNote::class;

    public function definition(): array
    {
        return [
            'debit_note_number' => 'CDN-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'customer_invoice_id' => null,
            'partner_id' => Partner::factory()->customer(),
            'status' => DocumentStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'reason' => DebitNoteReason::PriceIncrease,
            'reason_description' => null,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'issued_at' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Confirmed,
            'issued_at' => now()->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Cancelled]);
    }
}
