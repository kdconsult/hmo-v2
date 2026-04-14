<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\SalesReturnStatus;
use App\Models\SalesReturn;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesReturnService
{
    public function __construct(
        private readonly StockService $stockService,
    ) {}

    /**
     * Confirm a Sales Return: receive all items back into stock.
     *
     * @throws InvalidArgumentException
     */
    public function confirm(SalesReturn $sr): void
    {
        if (! $sr->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot confirm Sales Return [{$sr->sr_number}]: status is [{$sr->status->value}]."
            );
        }

        $sr->loadMissing(['items.productVariant', 'warehouse']);

        if ($sr->items->isEmpty()) {
            throw new InvalidArgumentException(
                "Cannot confirm Sales Return [{$sr->sr_number}]: no items."
            );
        }

        DB::transaction(function () use ($sr) {
            foreach ($sr->items as $item) {
                $this->stockService->receive(
                    variant: $item->productVariant,
                    warehouse: $sr->warehouse,
                    quantity: (string) $item->quantity,
                    location: null,
                    reference: $sr,
                    type: MovementType::SalesReturn,
                );
            }

            $sr->status = SalesReturnStatus::Confirmed;
            $sr->returned_at = now()->toDateString();
            $sr->save();
        });
    }

    /**
     * Cancel a draft Sales Return.
     *
     * @throws InvalidArgumentException
     */
    public function cancel(SalesReturn $sr): void
    {
        if (! $sr->isEditable()) {
            throw new InvalidArgumentException(
                "Cannot cancel Sales Return [{$sr->sr_number}]: status is [{$sr->status->value}]."
            );
        }

        $sr->status = SalesReturnStatus::Cancelled;
        $sr->save();
    }
}
