<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteItem;
use Illuminate\Support\Facades\DB;

class CustomerCreditNoteService
{
    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
    ) {}

    /**
     * Recalculate a single credit note item's VAT and line totals, then save.
     * Requires item->customerCreditNote and item->vatRate to be loaded (or loadable).
     */
    public function recalculateItemTotals(CustomerCreditNoteItem $item): void
    {
        $pricingMode = $item->customerCreditNote->pricing_mode;
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
     * Recalculate a customer credit note's subtotal, tax_amount, and total from its items, then save.
     */
    public function recalculateDocumentTotals(CustomerCreditNote $creditNote): void
    {
        $creditNote->load('items');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($creditNote->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $taxAmount = bcadd($taxAmount, (string) $item->vat_amount, 2);
        }

        $creditNote->subtotal = $subtotal;
        $creditNote->tax_amount = $taxAmount;
        $creditNote->total = bcadd($subtotal, $taxAmount, 2);
        $creditNote->save();
    }

    /**
     * Confirm a credit note, wrapped in a transaction.
     * Future: update parent invoice balance, adjust OSS if applicable.
     */
    public function confirm(CustomerCreditNote $ccn): void
    {
        DB::transaction(function () use ($ccn): void {
            $ccn->update(['status' => DocumentStatus::Confirmed]);
        });
    }

    /**
     * Cancel a credit note, wrapped in a transaction.
     * Future: reverse any balance or OSS changes applied on confirm.
     */
    public function cancel(CustomerCreditNote $ccn): void
    {
        DB::transaction(function () use ($ccn): void {
            $ccn->update(['status' => DocumentStatus::Cancelled]);
        });
    }
}
