<?php

namespace App\Services;

use App\Enums\PricingMode;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;

class SupplierInvoiceService
{
    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
    ) {}

    /**
     * Recalculate a single invoice item's discount, VAT, and line totals, then save.
     * Requires item->supplierInvoice and item->vatRate to be loaded (or loadable).
     */
    public function recalculateItemTotals(SupplierInvoiceItem $item): void
    {
        $pricingMode = $item->supplierInvoice->pricing_mode;
        $vatRate = (float) $item->vatRate->rate;

        $base = bcmul((string) $item->quantity, (string) $item->unit_price, 4);
        $discountAmount = bcmul($base, bcdiv((string) $item->discount_percent, '100', 6), 2);
        $baseAfterDiscount = bcsub($base, $discountAmount, 4);

        $result = match ($pricingMode) {
            PricingMode::VatExclusive => $this->vatCalculationService->fromNet((float) $baseAfterDiscount, $vatRate),
            PricingMode::VatInclusive => $this->vatCalculationService->fromGross((float) $baseAfterDiscount, $vatRate),
        };

        $item->discount_amount = number_format((float) $discountAmount, 2, '.', '');
        $item->vat_amount = number_format($result['vat'], 2, '.', '');
        $item->line_total = number_format($result['net'], 2, '.', '');
        $item->line_total_with_vat = number_format($result['gross'], 2, '.', '');
        $item->save();
    }

    /**
     * Recalculate a supplier invoice's subtotal, tax_amount, total, and amount_due from its items, then save.
     */
    public function recalculateDocumentTotals(SupplierInvoice $invoice): void
    {
        $invoice->load('items');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($invoice->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $taxAmount = bcadd($taxAmount, (string) $item->vat_amount, 2);
        }

        $invoice->subtotal = $subtotal;
        $invoice->tax_amount = $taxAmount;
        $invoice->total = bcadd(
            bcsub($subtotal, (string) $invoice->discount_amount, 2),
            $taxAmount,
            2
        );
        $invoice->amount_due = bcsub((string) $invoice->total, (string) $invoice->amount_paid, 2);
        $invoice->save();
    }
}
