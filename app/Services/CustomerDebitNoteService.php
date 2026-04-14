<?php

namespace App\Services;

use App\Enums\PricingMode;
use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteItem;

class CustomerDebitNoteService
{
    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
    ) {}

    /**
     * Recalculate a single debit note item's VAT and line totals, then save.
     * Requires item->customerDebitNote and item->vatRate to be loaded (or loadable).
     */
    public function recalculateItemTotals(CustomerDebitNoteItem $item): void
    {
        $pricingMode = $item->customerDebitNote->pricing_mode;
        $vatRate = (float) $item->vatRate->rate;

        $base = bcmul((string) $item->quantity, (string) $item->unit_price, 4);

        $result = match ($pricingMode) {
            PricingMode::VatExclusive => $this->vatCalculationService->fromNet((float) $base, $vatRate),
            PricingMode::VatInclusive => $this->vatCalculationService->fromGross((float) $base, $vatRate),
        };

        $item->vat_amount = number_format($result['vat'], 2, '.', '');
        $item->line_total = number_format($result['net'], 2, '.', '');
        $item->line_total_with_vat = number_format($result['gross'], 2, '.', '');
        $item->save();
    }

    /**
     * Recalculate a customer debit note's subtotal, tax_amount, and total from its items, then save.
     */
    public function recalculateDocumentTotals(CustomerDebitNote $debitNote): void
    {
        $debitNote->load('items');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($debitNote->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $taxAmount = bcadd($taxAmount, (string) $item->vat_amount, 2);
        }

        $debitNote->subtotal = $subtotal;
        $debitNote->tax_amount = $taxAmount;
        $debitNote->total = bcadd($subtotal, $taxAmount, 2);
        $debitNote->save();
    }
}
