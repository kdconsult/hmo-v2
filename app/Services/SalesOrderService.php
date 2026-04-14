<?php

namespace App\Services;

use App\Enums\DeliveryNoteStatus;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesOrderService
{
    /** @var array<string, SalesOrderStatus[]> */
    private array $validTransitions = [];

    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
        private readonly StockService $stockService,
    ) {
        $this->validTransitions = [
            SalesOrderStatus::Draft->value => [
                SalesOrderStatus::Confirmed,
                SalesOrderStatus::Cancelled,
            ],
            SalesOrderStatus::Confirmed->value => [
                SalesOrderStatus::PartiallyDelivered,
                SalesOrderStatus::Delivered,
                SalesOrderStatus::Invoiced,
                SalesOrderStatus::Cancelled,
            ],
            SalesOrderStatus::PartiallyDelivered->value => [
                SalesOrderStatus::Delivered,
                SalesOrderStatus::Cancelled,
            ],
            SalesOrderStatus::Delivered->value => [
                SalesOrderStatus::Invoiced,
                SalesOrderStatus::Cancelled,
            ],
        ];
    }

    /**
     * Recalculate a single item's discount, VAT, and line totals, then save.
     * Requires item->salesOrder and item->vatRate to be loaded (or loadable).
     */
    public function recalculateItemTotals(SalesOrderItem $item): void
    {
        $pricingMode = $item->salesOrder->pricing_mode;
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
     * Recalculate a sales order's subtotal, tax_amount, and total from its items, then save.
     */
    public function recalculateDocumentTotals(SalesOrder $order): void
    {
        $order->load('items');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($order->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $taxAmount = bcadd($taxAmount, (string) $item->vat_amount, 2);
        }

        $order->subtotal = $subtotal;
        $order->tax_amount = $taxAmount;
        $order->total = bcadd(
            bcsub($subtotal, (string) $order->discount_amount, 2),
            $taxAmount,
            2
        );
        $order->save();
    }

    /**
     * Transition the sales order to a new status.
     * On Confirmed: reserves stock for all stock-type items.
     * On Cancelled: checks for confirmed DNs, unreserves remaining, cascades to draft DNs/invoices.
     *
     * @throws InvalidArgumentException on invalid transition
     * @throws InsufficientStockException when stock is insufficient for reservation
     */
    public function transitionStatus(SalesOrder $order, SalesOrderStatus $newStatus): void
    {
        $allowed = $this->validTransitions[$order->status->value] ?? [];

        if (! in_array($newStatus, $allowed, strict: true)) {
            throw new InvalidArgumentException(
                "Cannot transition sales order from [{$order->status->value}] to [{$newStatus->value}]."
            );
        }

        if ($newStatus !== SalesOrderStatus::Cancelled && ! $order->items()->exists()) {
            throw new InvalidArgumentException(
                'Cannot transition: sales order has no line items.'
            );
        }

        if ($newStatus === SalesOrderStatus::Cancelled) {
            $hasConfirmedDns = $order->deliveryNotes()
                ->where('status', DeliveryNoteStatus::Confirmed->value)
                ->exists();

            if ($hasConfirmedDns) {
                throw new InvalidArgumentException(
                    'Cannot cancel: deliveries have already been confirmed against this order.'
                );
            }
        }

        if ($newStatus === SalesOrderStatus::Confirmed) {
            $this->reserveAllItems($order);
        }

        if ($newStatus === SalesOrderStatus::Cancelled) {
            $this->unreserveRemainingItems($order);

            $order->deliveryNotes()
                ->where('status', DeliveryNoteStatus::Draft->value)
                ->each(fn (DeliveryNote $dn) => $dn->update(['status' => DeliveryNoteStatus::Cancelled->value]));

            $order->customerInvoices()
                ->where('status', DocumentStatus::Draft->value)
                ->each(fn (CustomerInvoice $invoice) => $invoice->update(['status' => DocumentStatus::Cancelled->value]));
        }

        $order->status = $newStatus;
        $order->save();
    }

    /**
     * Reserve stock for all stock-type items on the order, in a single transaction.
     * Called when the order transitions to Confirmed.
     *
     * @throws InsufficientStockException
     */
    public function reserveAllItems(SalesOrder $order): void
    {
        $order->load(['items.productVariant.product', 'warehouse']);

        DB::transaction(function () use ($order): void {
            foreach ($order->items as $item) {
                if (! $item->productVariant) {
                    continue;
                }

                if ($item->productVariant->product?->type !== ProductType::Stock) {
                    continue;
                }

                $this->stockService->reserve(
                    $item->productVariant,
                    $order->warehouse,
                    (string) $item->quantity,
                    $order,
                );
            }
        });
    }

    /**
     * Unreserve the remaining (undelivered) quantity for all stock-type items.
     * Called when the order is cancelled.
     */
    public function unreserveRemainingItems(SalesOrder $order): void
    {
        $order->load(['items.productVariant.product', 'warehouse']);

        foreach ($order->items as $item) {
            if (! $item->productVariant) {
                continue;
            }

            if ($item->productVariant->product?->type !== ProductType::Stock) {
                continue;
            }

            $remainingQty = bcsub((string) $item->quantity, (string) $item->qty_delivered, 4);

            if (bccomp($remainingQty, '0', 4) <= 0) {
                continue;
            }

            $this->stockService->unreserve(
                $item->productVariant,
                $order->warehouse,
                $remainingQty,
                $order,
            );
        }
    }

    /**
     * Recalculate qty_delivered for each SO item from confirmed Delivery Notes,
     * then update SO status to PartiallyDelivered or Delivered.
     */
    public function updateDeliveredQuantities(SalesOrder $order): void
    {
        $order->load(['items.deliveryNoteItems.deliveryNote']);

        $anyDelivered = false;

        foreach ($order->items as $item) {
            $delivered = $item->deliveryNoteItems
                ->filter(fn (DeliveryNoteItem $dnItem) => $dnItem->deliveryNote?->isConfirmed())
                ->sum(fn (DeliveryNoteItem $dnItem) => (float) $dnItem->quantity);

            $item->qty_delivered = number_format($delivered, 4, '.', '');
            $item->save();

            if ($delivered > 0) {
                $anyDelivered = true;
            }
        }

        // Reload fresh qty_delivered values before checking full delivery
        $order->load('items');

        if ($order->isFullyDelivered()) {
            $order->status = SalesOrderStatus::Delivered;
            $order->save();
        } elseif ($anyDelivered) {
            $order->status = SalesOrderStatus::PartiallyDelivered;
            $order->save();
        }
    }

    /**
     * Recalculate qty_invoiced for each SO item from confirmed Customer Invoices,
     * then update SO status to Invoiced when all items are fully invoiced.
     */
    public function updateInvoicedQuantities(SalesOrder $order): void
    {
        $order->load(['items.customerInvoiceItems.customerInvoice']);

        foreach ($order->items as $item) {
            $invoiced = $item->customerInvoiceItems
                ->filter(fn (CustomerInvoiceItem $invItem) => $invItem->customerInvoice?->status === DocumentStatus::Confirmed)
                ->sum(fn (CustomerInvoiceItem $invItem) => (float) $invItem->quantity);

            $item->qty_invoiced = number_format($invoiced, 4, '.', '');
            $item->save();
        }

        $order->load('items');

        if ($order->isFullyInvoiced()) {
            $order->status = SalesOrderStatus::Invoiced;
            $order->save();
        }
    }
}
