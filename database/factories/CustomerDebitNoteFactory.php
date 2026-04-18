<?php

namespace Database\Factories;

use App\Enums\DebitNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
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
            'vat_scenario' => null,
            'vat_scenario_sub_code' => null,
            'is_reverse_charge' => false,
            'triggering_event_date' => null,
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

    public function withParent(CustomerInvoice $parent): static
    {
        return $this->state(fn () => [
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => $parent->currency_code,
            'exchange_rate' => $parent->exchange_rate,
            'pricing_mode' => $parent->pricing_mode,
            'vat_scenario' => $parent->vat_scenario,
            'vat_scenario_sub_code' => $parent->vat_scenario_sub_code,
            'is_reverse_charge' => $parent->is_reverse_charge,
        ]);
    }

    public function standalone(): static
    {
        return $this->state(fn () => [
            'customer_invoice_id' => null,
        ]);
    }
}
