<?php

namespace Database\Factories;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\Partner;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupplierCreditNote>
 */
class SupplierCreditNoteFactory extends Factory
{
    protected $model = SupplierCreditNote::class;

    public function definition(): array
    {
        return [
            'credit_note_number' => 'SCN-'.strtoupper(Str::random(8)),
            'supplier_invoice_id' => SupplierInvoice::factory(),
            'partner_id' => Partner::factory()->supplier(),
            'status' => DocumentStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'pricing_mode' => PricingMode::VatExclusive,
            'reason' => CreditNoteReason::Return,
            'reason_description' => null,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'issued_at' => now()->toDateString(),
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Cancelled]);
    }
}
