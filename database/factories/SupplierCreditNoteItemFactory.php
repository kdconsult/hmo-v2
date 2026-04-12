<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteItem;
use App\Models\SupplierInvoiceItem;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierCreditNoteItem>
 */
class SupplierCreditNoteItemFactory extends Factory
{
    protected $model = SupplierCreditNoteItem::class;

    public function definition(): array
    {
        $quantity = fake()->randomFloat(4, 1, 10);
        $unitPrice = fake()->randomFloat(4, 1, 500);
        $lineTotal = round($quantity * $unitPrice, 2);

        return [
            'supplier_credit_note_id' => SupplierCreditNote::factory(),
            'supplier_invoice_item_id' => SupplierInvoiceItem::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'description' => fake()->words(3, true),
            'quantity' => number_format($quantity, 4, '.', ''),
            'unit_price' => number_format($unitPrice, 4, '.', ''),
            'vat_rate_id' => VatRate::factory(),
            'vat_amount' => '0.00',
            'line_total' => number_format($lineTotal, 2, '.', ''),
            'line_total_with_vat' => number_format($lineTotal, 2, '.', ''),
            'sort_order' => 0,
        ];
    }
}
