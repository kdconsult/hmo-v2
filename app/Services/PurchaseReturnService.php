<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PurchaseReturnStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\PurchaseReturn;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseReturnService
{
    public function __construct(
        private readonly StockService $stockService,
    ) {}

    /**
     * Confirm a Purchase Return: issue stock out of the warehouse for each item.
     *
     * @throws InvalidArgumentException
     * @throws InsufficientStockException
     */
    public function confirm(PurchaseReturn $pr): void
    {
        if (! $pr->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot confirm Purchase Return [{$pr->pr_number}]: status is [{$pr->status->value}]."
            );
        }

        $pr->loadMissing(['items.productVariant', 'warehouse']);

        if ($pr->items->isEmpty()) {
            throw new InvalidArgumentException(
                "Cannot confirm Purchase Return [{$pr->pr_number}]: no items."
            );
        }

        DB::transaction(function () use ($pr) {
            foreach ($pr->items as $item) {
                $this->stockService->issue(
                    variant: $item->productVariant,
                    warehouse: $pr->warehouse,
                    quantity: (string) $item->quantity,
                    location: null,
                    reference: $pr,
                    type: MovementType::PurchaseReturn,
                );
            }

            $pr->status = PurchaseReturnStatus::Confirmed;
            $pr->returned_at = now()->toDateString();
            $pr->save();
        });
    }

    /**
     * Cancel a draft Purchase Return.
     *
     * @throws InvalidArgumentException
     */
    public function cancel(PurchaseReturn $pr): void
    {
        if (! $pr->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot cancel Purchase Return [{$pr->pr_number}]: status is [{$pr->status->value}]."
            );
        }

        $pr->status = PurchaseReturnStatus::Cancelled;
        $pr->save();
    }
}
