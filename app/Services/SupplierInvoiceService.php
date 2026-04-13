<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\GoodsReceivedNoteStatus;
use App\Enums\PricingMode;
use App\Enums\SeriesType;
use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\NumberSeries;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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

    /**
     * Confirm a supplier invoice and immediately create + confirm a GRN for all stockable lines.
     * Used by the Express Purchasing "Confirm & Receive" action.
     *
     * @throws InvalidArgumentException
     */
    public function confirmAndReceive(SupplierInvoice $invoice, Warehouse $warehouse): GoodsReceivedNote
    {
        if (! $invoice->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot confirm invoice [{$invoice->internal_number}]: status is [{$invoice->status->value}]."
            );
        }

        $invoice->loadMissing(['items.productVariant']);

        $stockableItems = $invoice->items->filter(fn ($item) => $item->product_variant_id !== null);

        if ($stockableItems->isEmpty()) {
            throw new InvalidArgumentException(
                'Cannot confirm & receive: no stockable items (all lines are free-text).'
            );
        }

        $series = NumberSeries::getDefault(SeriesType::GoodsReceivedNote);
        if (! $series) {
            throw new InvalidArgumentException(
                'No active number series configured for Goods Receipts. Go to Settings → Number Series.'
            );
        }

        return DB::transaction(function () use ($invoice, $warehouse, $stockableItems, $series) {
            // 1. Confirm the SI
            $invoice->status = DocumentStatus::Confirmed;
            $invoice->save();

            // 2. Create GRN linked to SI (and PO if SI is PO-linked)
            $grn = GoodsReceivedNote::create([
                'grn_number' => $series->generateNumber(),
                'document_series_id' => $series->id,
                'purchase_order_id' => $invoice->purchase_order_id,
                'supplier_invoice_id' => $invoice->id,
                'partner_id' => $invoice->partner_id,
                'warehouse_id' => $warehouse->id,
                'status' => GoodsReceivedNoteStatus::Draft,
                'received_at' => now()->toDateString(),
                'created_by' => Auth::id(),
            ]);

            // 3. Create GRN items from stockable SI lines (free-text lines skipped)
            foreach ($stockableItems as $siItem) {
                GoodsReceivedNoteItem::create([
                    'goods_received_note_id' => $grn->id,
                    'purchase_order_item_id' => $siItem->purchase_order_item_id,
                    'product_variant_id' => $siItem->product_variant_id,
                    'quantity' => $siItem->quantity,
                    'unit_cost' => $siItem->unit_price,
                ]);
            }

            // 4. Confirm GRN — stock moves in, PO qty_received updates if PO-linked
            app(GoodsReceiptService::class)->confirm($grn);

            return $grn;
        });
    }
}
