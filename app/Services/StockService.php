<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariant;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
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

    /**
     * Reserve stock for a future outbound movement (e.g. confirmed sales order).
     * Increases reserved_quantity without creating a StockMovement.
     *
     * @throws InsufficientStockException
     */
    public function reserve(
        ProductVariant $variant,
        Warehouse $warehouse,
        string $quantity,
        Model $reference,
    ): void {
        DB::transaction(function () use ($variant, $warehouse, $quantity) {
            $stockItem = $this->findOrCreateStockItem($variant, $warehouse, null);

            $available = bcsub((string) $stockItem->quantity, (string) $stockItem->reserved_quantity, 4);

            if (bccomp($available, $quantity, 4) < 0) {
                throw new InsufficientStockException($variant, $warehouse, $quantity, $available);
            }

            $stockItem->reserved_quantity = bcadd((string) $stockItem->reserved_quantity, $quantity, 4);
            $stockItem->save();
        });
    }

    /**
     * Release a previously reserved quantity (e.g. cancelled sales order).
     * Decreases reserved_quantity without creating a StockMovement. Floors at 0.
     */
    public function unreserve(
        ProductVariant $variant,
        Warehouse $warehouse,
        string $quantity,
        Model $reference,
    ): void {
        DB::transaction(function () use ($variant, $warehouse, $quantity) {
            $stockItem = $this->findOrCreateStockItem($variant, $warehouse, null);

            $newReserved = bcsub((string) $stockItem->reserved_quantity, $quantity, 4);

            // Floor at zero to handle over-unreservation gracefully
            if (bccomp($newReserved, '0', 4) < 0) {
                $newReserved = '0.0000';
            }

            $stockItem->reserved_quantity = $newReserved;
            $stockItem->save();
        });
    }

    /**
     * Atomically issue stock that was previously reserved (e.g. confirmed delivery note).
     * Uses a single SQL UPDATE with guards to prevent race conditions.
     * Creates a StockMovement with MovementType::Sale.
     *
     * @throws InsufficientStockException
     */
    public function issueReserved(
        ProductVariant $variant,
        Warehouse $warehouse,
        string $quantity,
        Model $reference,
        ?User $by = null,
    ): StockMovement {
        return DB::transaction(function () use ($variant, $warehouse, $quantity, $reference, $by) {
            $affected = DB::update(
                'UPDATE stock_items
                 SET quantity = quantity - ?,
                     reserved_quantity = reserved_quantity - ?
                 WHERE product_variant_id = ?
                   AND warehouse_id = ?
                   AND stock_location_id IS NULL
                   AND reserved_quantity >= ?
                   AND quantity >= ?',
                [$quantity, $quantity, $variant->id, $warehouse->id, $quantity, $quantity]
            );

            if ($affected !== 1) {
                $stockItem = StockItem::where('product_variant_id', $variant->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->whereNull('stock_location_id')
                    ->first();

                $available = $stockItem
                    ? (string) $stockItem->reserved_quantity
                    : '0.0000';

                throw new InsufficientStockException($variant, $warehouse, $quantity, $available);
            }

            return StockMovement::create([
                'product_variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
                'stock_location_id' => null,
                'type' => MovementType::Sale,
                'quantity' => '-'.$quantity,
                'reference_type' => $reference->getMorphClass(),
                'reference_id' => $reference->id,
                'notes' => null,
                'moved_by' => $by?->id ?? Auth::id(),
            ]);
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
            'reference_type' => $reference ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->id,
            'notes' => $notes,
            'moved_by' => Auth::id(),
        ]);
    }
}
