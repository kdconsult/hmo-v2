<?php

namespace App\Services;

use App\Enums\PricingMode;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceivedNoteItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use InvalidArgumentException;

class PurchaseOrderService
{
    /** @var array<string, PurchaseOrderStatus[]> */
    private array $validTransitions = [];

    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
    ) {
        $this->validTransitions = [
            PurchaseOrderStatus::Draft->value => [
                PurchaseOrderStatus::Sent,
                PurchaseOrderStatus::Cancelled,
            ],
            PurchaseOrderStatus::Sent->value => [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Confirmed,
                PurchaseOrderStatus::Cancelled,
            ],
            PurchaseOrderStatus::Confirmed->value => [
                PurchaseOrderStatus::PartiallyReceived,
                PurchaseOrderStatus::Received,
                PurchaseOrderStatus::Cancelled,
            ],
            PurchaseOrderStatus::PartiallyReceived->value => [
                PurchaseOrderStatus::Received,
                PurchaseOrderStatus::Cancelled,
            ],
        ];
    }

    /**
     * Recalculate a single item's discount, VAT, and line totals, then save.
     * Requires item->purchaseOrder and item->vatRate to be loaded (or loadable).
     */
    public function recalculateItemTotals(PurchaseOrderItem $item): void
    {
        $pricingMode = $item->purchaseOrder->pricing_mode;
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
     * Recalculate a PO's subtotal, tax_amount, and total from its items, then save.
     */
    public function recalculateDocumentTotals(PurchaseOrder $po): void
    {
        $po->load('items');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($po->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $taxAmount = bcadd($taxAmount, (string) $item->vat_amount, 2);
        }

        $po->subtotal = $subtotal;
        $po->tax_amount = $taxAmount;
        $po->total = bcadd(
            bcsub($subtotal, (string) $po->discount_amount, 2),
            $taxAmount,
            2
        );
        $po->save();
    }

    /**
     * Transition the PO to a new status.
     *
     * @throws InvalidArgumentException on invalid transition
     */
    public function transitionStatus(PurchaseOrder $po, PurchaseOrderStatus $newStatus): void
    {
        $allowed = $this->validTransitions[$po->status->value] ?? [];

        if (! in_array($newStatus, $allowed, strict: true)) {
            throw new InvalidArgumentException(
                "Cannot transition purchase order from [{$po->status->value}] to [{$newStatus->value}]."
            );
        }

        $po->status = $newStatus;
        $po->save();
    }

    /**
     * Recalculate quantity_received for each PO item from all confirmed GRNs,
     * then update the PO status to PartiallyReceived or Received.
     */
    public function updateReceivedQuantities(PurchaseOrder $po): void
    {
        $po->load(['items.goodsReceivedNoteItems.goodsReceivedNote']);

        $anyReceived = false;

        foreach ($po->items as $item) {
            $received = $item->goodsReceivedNoteItems
                ->filter(fn (GoodsReceivedNoteItem $grnItem) => $grnItem->goodsReceivedNote?->isConfirmed())
                ->sum(fn (GoodsReceivedNoteItem $grnItem) => (float) $grnItem->quantity);

            $item->quantity_received = number_format($received, 4, '.', '');
            $item->save();

            if ($received > 0) {
                $anyReceived = true;
            }
        }

        // Reload items with fresh quantity_received to check fully received
        $po->load('items');

        if ($po->isFullyReceived()) {
            $po->status = PurchaseOrderStatus::Received;
            $po->save();
        } elseif ($anyReceived) {
            $po->status = PurchaseOrderStatus::PartiallyReceived;
            $po->save();
        }
    }
}
