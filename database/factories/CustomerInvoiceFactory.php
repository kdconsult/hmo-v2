<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\PricingMode;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerInvoice>
 */
class CustomerInvoiceFactory extends Factory
{
    protected $model = CustomerInvoice::class;

    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'sales_order_id' => null,
            'partner_id' => Partner::factory()->customer(),
            'status' => DocumentStatus::Draft,
            'invoice_type' => InvoiceType::Standard,
            'is_reverse_charge' => false,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'amount_paid' => '0.00',
            'amount_due' => '0.00',
            'payment_method' => null,
            'issued_at' => null,
            'due_date' => null,
            'notes' => null,
            'internal_notes' => null,
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
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Cancelled]);
    }
}
