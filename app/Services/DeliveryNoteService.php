<?php

namespace App\Services;

use App\Enums\DeliveryNoteStatus;
use App\Enums\ProductType;
use App\Exceptions\InsufficientStockException;
use App\Models\DeliveryNote;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeliveryNoteService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly SalesOrderService $salesOrderService,
    ) {}

    /**
     * Confirm a Delivery Note: issue reserved stock from the warehouse for each stock-type item
     * and update the linked SO's delivered quantities.
     *
     * @throws InvalidArgumentException
     * @throws InsufficientStockException
     */
    public function confirm(DeliveryNote $dn): void
    {
        if (! $dn->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot confirm Delivery Note [{$dn->dn_number}]: status is [{$dn->status->value}]."
            );
        }

        $dn->loadMissing(['items.productVariant.product', 'warehouse']);

        if ($dn->items->isEmpty()) {
            throw new InvalidArgumentException(
                "Cannot confirm Delivery Note [{$dn->dn_number}]: no items."
            );
        }

        DB::transaction(function () use ($dn) {
            foreach ($dn->items as $item) {
                if (! $item->productVariant) {
                    continue;
                }

                if ($item->productVariant->product?->type !== ProductType::Stock) {
                    continue;
                }

                $this->stockService->issueReserved(
                    variant: $item->productVariant,
                    warehouse: $dn->warehouse,
                    quantity: (string) $item->quantity,
                    reference: $dn,
                );
            }

            $dn->status = DeliveryNoteStatus::Confirmed;
            $dn->delivered_at = now()->toDateString();
            $dn->save();

            if ($dn->sales_order_id) {
                $dn->loadMissing('salesOrder');
                $this->salesOrderService->updateDeliveredQuantities($dn->salesOrder);
            }
        });
    }

    /**
     * Cancel a draft Delivery Note.
     *
     * @throws InvalidArgumentException
     */
    public function cancel(DeliveryNote $dn): void
    {
        if (! $dn->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot cancel Delivery Note [{$dn->dn_number}]: status is [{$dn->status->value}]."
            );
        }

        $dn->status = DeliveryNoteStatus::Cancelled;
        $dn->save();
    }
}
