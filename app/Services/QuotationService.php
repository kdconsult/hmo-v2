<?php

namespace App\Services;

use App\Enums\PricingMode;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SeriesType;
use App\Models\NumberSeries;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

class QuotationService
{
    /** @var array<string, QuotationStatus[]> */
    private array $validTransitions = [];

    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
    ) {
        $this->validTransitions = [
            QuotationStatus::Draft->value => [
                QuotationStatus::Sent,
                QuotationStatus::Cancelled,
            ],
            QuotationStatus::Sent->value => [
                QuotationStatus::Accepted,
                QuotationStatus::Rejected,
                QuotationStatus::Expired,
                QuotationStatus::Cancelled,
            ],
            QuotationStatus::Accepted->value => [
                QuotationStatus::Cancelled,
            ],
        ];
    }

    /**
     * Recalculate a single item's discount, VAT, and line totals, then save.
     * Requires item->quotation and item->vatRate to be loaded (or loadable).
     */
    public function recalculateItemTotals(QuotationItem $item): void
    {
        $pricingMode = $item->quotation->pricing_mode;
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
     * Recalculate a quotation's subtotal, tax_amount, and total from its items, then save.
     */
    public function recalculateDocumentTotals(Quotation $quotation): void
    {
        $quotation->load('items');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($quotation->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $taxAmount = bcadd($taxAmount, (string) $item->vat_amount, 2);
        }

        $quotation->subtotal = $subtotal;
        $quotation->tax_amount = $taxAmount;
        $quotation->total = bcadd(
            bcsub($subtotal, (string) $quotation->discount_amount, 2),
            $taxAmount,
            2
        );
        $quotation->save();
    }

    /**
     * Transition the quotation to a new status.
     *
     * @throws InvalidArgumentException on invalid transition
     */
    public function transitionStatus(Quotation $quotation, QuotationStatus $newStatus): void
    {
        $allowed = $this->validTransitions[$quotation->status->value] ?? [];

        if (! in_array($newStatus, $allowed, strict: true)) {
            throw new InvalidArgumentException(
                "Cannot transition quotation from [{$quotation->status->value}] to [{$newStatus->value}]."
            );
        }

        if ($newStatus !== QuotationStatus::Cancelled && ! $quotation->items()->exists()) {
            throw new InvalidArgumentException(
                'Cannot transition: quotation has no line items.'
            );
        }

        $quotation->status = $newStatus;
        $quotation->save();
    }

    /**
     * Convert an accepted quotation to a new Sales Order.
     * Copies all line items with quotation_item_id linkage.
     * Does NOT change the quotation status.
     */
    public function convertToSalesOrder(Quotation $quotation, Warehouse $warehouse): SalesOrder
    {
        $quotation->load('items');

        $series = NumberSeries::getDefault(SeriesType::SalesOrder);
        $soNumber = $series
            ? $series->generateNumber()
            : 'SO-'.strtoupper(Str::random(8));

        $salesOrder = SalesOrder::create([
            'so_number' => $soNumber,
            'document_series_id' => $series?->id,
            'partner_id' => $quotation->partner_id,
            'quotation_id' => $quotation->id,
            'warehouse_id' => $warehouse->id,
            'currency_code' => $quotation->currency_code,
            'exchange_rate' => $quotation->exchange_rate,
            'pricing_mode' => $quotation->pricing_mode,
            'subtotal' => $quotation->subtotal,
            'discount_amount' => $quotation->discount_amount,
            'tax_amount' => $quotation->tax_amount,
            'total' => $quotation->total,
            'status' => SalesOrderStatus::Draft->value,
            'created_by' => Auth::id(),
        ]);

        foreach ($quotation->items as $item) {
            $salesOrder->items()->create([
                'quotation_item_id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_percent' => $item->discount_percent,
                'discount_amount' => $item->discount_amount,
                'vat_rate_id' => $item->vat_rate_id,
                'vat_amount' => $item->vat_amount,
                'line_total' => $item->line_total,
                'line_total_with_vat' => $item->line_total_with_vat,
                'sort_order' => $item->sort_order,
            ]);
        }

        return $salesOrder;
    }
}
