<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Events\FiscalReceiptRequested;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use Illuminate\Support\Facades\DB;

class CustomerInvoiceService
{
    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
    ) {}

    /**
     * Recalculate a single invoice item's discount, VAT, and line totals, then save.
     * Requires item->customerInvoice and item->vatRate to be loaded (or loadable).
     * Handles negative quantities correctly (e.g. advance deduction rows).
     */
    public function recalculateItemTotals(CustomerInvoiceItem $item): void
    {
        $pricingMode = $item->customerInvoice->pricing_mode;
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
     * Recalculate a customer invoice's subtotal, tax_amount, total, and amount_due from its items, then save.
     */
    public function recalculateDocumentTotals(CustomerInvoice $invoice): void
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
     * Confirm a customer invoice.
     * - Updates SO qty_invoiced if linked (and transitions SO to Invoiced when fully invoiced)
     * - For service-type SO items: sets qty_delivered = qty_invoiced (services are delivered on invoicing)
     * - Dispatches FiscalReceiptRequested on cash payment
     * - Accumulates EU OSS totals if applicable
     */
    public function confirm(CustomerInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice->status = DocumentStatus::Confirmed;
            $invoice->save();

            if ($invoice->sales_order_id) {
                $so = $invoice->salesOrder;

                app(SalesOrderService::class)->updateInvoicedQuantities($so);

                // For service-type SO items: services are delivered when invoiced
                $invoice->loadMissing(['items.salesOrderItem.productVariant.product']);

                foreach ($invoice->items as $item) {
                    if (! $item->sales_order_item_id) {
                        continue;
                    }

                    $soItem = $item->salesOrderItem;
                    if (! $soItem) {
                        continue;
                    }

                    $productType = $soItem->productVariant?->product?->type;
                    if ($productType === ProductType::Stock) {
                        continue;
                    }

                    // Service/Bundle: delivered when invoiced
                    $soItem->refresh(); // get fresh qty_invoiced after updateInvoicedQuantities()
                    $soItem->qty_delivered = $soItem->qty_invoiced;
                    $soItem->save();
                }

                // Check if SO is now fully delivered (service orders may become fully delivered here)
                $so->load('items');
                if ($so->status !== SalesOrderStatus::Delivered && $so->status !== SalesOrderStatus::Invoiced) {
                    if ($so->isFullyDelivered()) {
                        $so->status = SalesOrderStatus::Delivered;
                        $so->save();
                    }
                }
            }
        });

        if ($invoice->payment_method === PaymentMethod::Cash) {
            FiscalReceiptRequested::dispatch($invoice);
        }

        // Accumulate EU OSS amounts for cross-border B2C tracking
        $invoice->loadMissing('partner');
        app(EuOssService::class)->accumulate($invoice);
    }
}
