<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariant;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Receive stock into a warehouse (inbound movement).
     */
    public function receive(
        ProductVariant $variant,
        Warehouse $warehouse,
        string $quantity,
        ?StockLocation $location = null,
        ?Model $reference = null,
        MovementType $type = MovementType::Purchase,
    ): StockItem {
        return DB::transaction(function () use ($variant, $warehouse, $quantity, $location, $reference, $type) {
            $stockItem = $this->findOrCreateStockItem($variant, $warehouse, $location);

            $stockItem->quantity = bcadd((string) $stockItem->quantity, $quantity, 4);
            $stockItem->save();

            $this->createMovement($variant, $warehouse, $location, $type, $quantity, $reference);

            return $stockItem;
        });
    }

    /**
     * Issue stock from a warehouse (outbound movement).
     *
     * @throws InsufficientStockException
     */
    public function issue(
        ProductVariant $variant,
        Warehouse $warehouse,
        string $quantity,
        ?StockLocation $location = null,
        ?Model $reference = null,
        MovementType $type = MovementType::Sale,
    ): StockItem {
        return DB::transaction(function () use ($variant, $warehouse, $quantity, $location, $reference, $type) {
            $stockItem = $this->findOrCreateStockItem($variant, $warehouse, $location);

            $available = bcsub((string) $stockItem->quantity, (string) $stockItem->reserved_quantity, 4);

            if (bccomp($available, $quantity, 4) < 0) {
                throw new InsufficientStockException($variant, $warehouse, $quantity, $available);
            }

            $stockItem->quantity = bcsub((string) $stockItem->quantity, $quantity, 4);
            $stockItem->save();

            $negativeQty = '-'.$quantity;
            $this->createMovement($variant, $warehouse, $location, $type, $negativeQty, $reference);

            return $stockItem;
        });
    }

    /**
     * Adjust stock quantity (positive or negative signed value).
     */
    public function adjust(
        ProductVariant $variant,
        Warehouse $warehouse,
        string $quantity,
        string $reason,
        ?StockLocation $location = null,
    ): StockItem {
        return DB::transaction(function () use ($variant, $warehouse, $quantity, $reason, $location) {
            $stockItem = $this->findOrCreateStockItem($variant, $warehouse, $location);

            $stockItem->quantity = bcadd((string) $stockItem->quantity, $quantity, 4);
            $stockItem->save();

            $this->createMovement($variant, $warehouse, $location, MovementType::Adjustment, $quantity, null, $reason);

            return $stockItem;
        });
    }

    /**
     * Transfer stock between warehouses (paired TransferOut + TransferIn).
     *
     * @return array{0: StockItem, 1: StockItem}
     *
     * @throws InsufficientStockException
     */
    public function transfer(
        ProductVariant $variant,
        Warehouse $fromWarehouse,
        Warehouse $toWarehouse,
        string $quantity,
        ?StockLocation $fromLocation = null,
        ?StockLocation $toLocation = null,
    ): array {
        return DB::transaction(function () use ($variant, $fromWarehouse, $toWarehouse, $quantity, $fromLocation, $toLocation) {
            $source = $this->findOrCreateStockItem($variant, $fromWarehouse, $fromLocation);

            $available = bcsub((string) $source->quantity, (string) $source->reserved_quantity, 4);

            if (bccomp($available, $quantity, 4) < 0) {
                throw new InsufficientStockException($variant, $fromWarehouse, $quantity, $available);
            }

            $source->quantity = bcsub((string) $source->quantity, $quantity, 4);
            $source->save();

            $destination = $this->findOrCreateStockItem($variant, $toWarehouse, $toLocation);
            $destination->quantity = bcadd((string) $destination->quantity, $quantity, 4);
            $destination->save();

            $negativeQty = '-'.$quantity;
            $this->createMovement($variant, $fromWarehouse, $fromLocation, MovementType::TransferOut, $negativeQty);
            $this->createMovement($variant, $toWarehouse, $toLocation, MovementType::TransferIn, $quantity);

            return [$source, $destination];
        });
    }

    private function findOrCreateStockItem(
        ProductVariant $variant,
        Warehouse $warehouse,
        ?StockLocation $location,
    ): StockItem {
        return StockItem::firstOrCreate([
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'stock_location_id' => $location?->id,
        ], [
            'quantity' => '0.0000',
            'reserved_quantity' => '0.0000',
        ]);
    }

    private function createMovement(
        ProductVariant $variant,
        Warehouse $warehouse,
        ?StockLocation $location,
        MovementType $type,
        string $quantity,
        ?Model $reference = null,
        ?string $notes = null,
    ): StockMovement {
        return StockMovement::create([
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'stock_location_id' => $location?->id,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->id,
            'notes' => $notes,
            'moved_by' => Auth::id(),
        ]);
    }
}
