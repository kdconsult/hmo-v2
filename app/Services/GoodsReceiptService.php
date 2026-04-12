<?php

namespace App\Services;

use App\Enums\GoodsReceivedNoteStatus;
use App\Enums\MovementType;
use App\Models\GoodsReceivedNote;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GoodsReceiptService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly PurchaseOrderService $purchaseOrderService,
    ) {}

    /**
     * Confirm a GRN: receive stock into the warehouse for each item
     * and update the linked PO's received quantities.
     *
     * @throws InvalidArgumentException
     */
    public function confirm(GoodsReceivedNote $grn): void
    {
        if (! $grn->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot confirm GRN [{$grn->grn_number}]: status is [{$grn->status->value}]."
            );
        }

        $grn->loadMissing(['items.productVariant', 'warehouse']);

        if ($grn->items->isEmpty()) {
            throw new InvalidArgumentException(
                "Cannot confirm GRN [{$grn->grn_number}]: no items."
            );
        }

        DB::transaction(function () use ($grn) {
            foreach ($grn->items as $item) {
                $this->stockService->receive(
                    variant: $item->productVariant,
                    warehouse: $grn->warehouse,
                    quantity: (string) $item->quantity,
                    location: null,
                    reference: $grn,
                    type: MovementType::Purchase,
                );
            }

            $grn->status = GoodsReceivedNoteStatus::Confirmed;
            $grn->received_at = now()->toDateString();
            $grn->save();

            if ($grn->purchase_order_id) {
                $grn->loadMissing('purchaseOrder');
                $this->purchaseOrderService->updateReceivedQuantities($grn->purchaseOrder);
            }
        });
    }

    /**
     * Cancel a draft GRN.
     *
     * @throws InvalidArgumentException
     */
    public function cancel(GoodsReceivedNote $grn): void
    {
        if (! $grn->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot cancel GRN [{$grn->grn_number}]: status is [{$grn->status->value}]."
            );
        }

        $grn->status = GoodsReceivedNoteStatus::Cancelled;
        $grn->save();
    }
}
