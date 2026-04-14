<?php

namespace Database\Factories;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerCreditNote>
 */
class CustomerCreditNoteFactory extends Factory
{
    protected $model = CustomerCreditNote::class;

    public function definition(): array
    {
        return [
            'credit_note_number' => 'CCN-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'customer_invoice_id' => CustomerInvoice::factory(),
            'partner_id' => Partner::factory()->customer(),
            'status' => DocumentStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'reason' => CreditNoteReason::Return,
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
