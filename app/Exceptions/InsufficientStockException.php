<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly ProductVariant $productVariant,
        public readonly Warehouse $warehouse,
        public readonly string $requestedQuantity,
        public readonly string $availableQuantity,
    ) {
        parent::__construct(sprintf(
            'Insufficient stock for "%s" (SKU: %s) in warehouse "%s". Requested: %s, Available: %s.',
            $productVariant->name,
            $productVariant->sku,
            $warehouse->name,
            $requestedQuantity,
            $availableQuantity,
        ));
    }
}
