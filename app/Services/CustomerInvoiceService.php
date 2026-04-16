<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Enums\VatScenario;
use App\Events\FiscalReceiptRequested;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\EuCountryVatRate;
use App\Models\VatRate;
use DomainException;
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
     * - Guards against over-invoicing SO items
     * - Updates SO qty_invoiced if linked (and transitions SO to Invoiced when fully invoiced)
     * - For service-type SO items: sets qty_delivered = qty_invoiced (services are delivered on invoicing)
     * - Dispatches FiscalReceiptRequested on cash payment
     * - Accumulates EU OSS totals if applicable
     *
     * @param  bool  $treatAsB2c  When true, the partner's stored VAT data is ignored and the
     *                            invoice is treated as B2C. Use when VIES explicitly rejected the
     *                            VAT number and the user chose to confirm with standard VAT.
     *
     * @throws DomainException when an item would over-invoice its SO line
     */
    public function confirm(CustomerInvoice $invoice, bool $treatAsB2c = false): void
    {
        if ($invoice->status !== DocumentStatus::Draft) {
            throw new DomainException('Only draft invoices can be confirmed.');
        }

        $invoice->loadMissing(['items.salesOrderItem']);

        foreach ($invoice->items as $item) {
            if (! $item->sales_order_item_id || ! $item->salesOrderItem) {
                continue;
            }

            $soItem = $item->salesOrderItem;
            $alreadyInvoiced = CustomerInvoiceItem::whereHas('customerInvoice', fn ($q) => $q
                ->where('sales_order_id', $invoice->sales_order_id)
                ->where('status', DocumentStatus::Confirmed)
                ->where('id', '!=', $invoice->id)
            )
                ->where('sales_order_item_id', $item->sales_order_item_id)
                ->sum('quantity');

            if (bccomp(bcadd((string) $alreadyInvoiced, (string) $item->quantity, 4), (string) $soItem->quantity, 4) > 0) {
                throw new DomainException(
                    "Over-invoice: item qty exceeds ordered qty for SO line #{$soItem->id}."
                );
            }
        }

        DB::transaction(function () use ($invoice, $treatAsB2c): void {
            $this->determineVatType($invoice, $treatAsB2c);

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

    /**
     * Determine the correct EU VAT treatment for an invoice and apply it.
     * Called inside the confirm() transaction before status is set to Confirmed.
     *
     * For scenarios that require a VAT rate change (B2B reverse charge, OSS, non-EU export):
     * - Resolves the target VatRate (zero-rate or destination country rate)
     * - Updates every item's vat_rate_id and recalculates its totals
     * - Recalculates document-level totals
     * - Sets is_reverse_charge on the invoice
     *
     * @param  bool  $treatAsB2c  When true, partner VAT data is ignored — OSS threshold check
     *                            still runs so over-threshold B2C gets the correct OSS rate.
     *
     * @throws DomainException when company country code is not configured
     * @throws DomainException when OSS destination country has no VAT rate record
     */
    private function determineVatType(CustomerInvoice $invoice, bool $treatAsB2c = false): void
    {
        $tenantCountry = CompanySettings::get('company', 'country_code');

        if (empty($tenantCountry)) {
            throw new DomainException('Company country code is not configured. Please set it in Company Settings.');
        }

        $invoice->loadMissing('partner');
        $partner = $invoice->partner;

        $scenario = VatScenario::determine($partner, $tenantCountry, ignorePartnerVat: $treatAsB2c);

        if (! $scenario->requiresVatRateChange()) {
            $invoice->is_reverse_charge = false;
            $invoice->save();

            return;
        }

        $invoice->is_reverse_charge = ($scenario === VatScenario::EuB2bReverseCharge);

        $targetVatRate = match ($scenario) {
            VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport => $this->resolveZeroVatRate($tenantCountry),
            VatScenario::EuB2cOverThreshold => $this->resolveOssVatRate($partner->country_code),
            default => throw new \LogicException("Unexpected scenario requiring VAT rate change: {$scenario->value}"),
        };

        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            $item->vat_rate_id = $targetVatRate->id;
            $item->save();
            $item->setRelation('customerInvoice', $invoice);
            $item->setRelation('vatRate', $targetVatRate);
            $this->recalculateItemTotals($item);
        }

        $this->recalculateDocumentTotals($invoice);
    }

    /**
     * Find or create a zero-rate VatRate for the tenant's country.
     * Used for EU B2B reverse charge and non-EU exports.
     */
    private function resolveZeroVatRate(string $countryCode): VatRate
    {
        return VatRate::firstOrCreate(
            ['country_code' => $countryCode, 'type' => 'zero'],
            ['name' => 'Zero Rate (0%)', 'rate' => 0, 'is_default' => false, 'is_active' => true]
        );
    }

    /**
     * Find or create a VatRate for the OSS destination country using reference rate data.
     * Used for EU B2C sales where the OSS threshold has been exceeded.
     *
     * @throws DomainException when no standard VAT rate is configured for the destination country
     */
    private function resolveOssVatRate(string $destinationCountry): VatRate
    {
        $rate = EuCountryVatRate::getStandardRate($destinationCountry);

        if ($rate === null) {
            throw new DomainException(
                "No EU standard VAT rate is configured for country {$destinationCountry}. Cannot apply OSS rate."
            );
        }

        return VatRate::firstOrCreate(
            ['country_code' => $destinationCountry, 'type' => 'standard'],
            ['name' => "Standard Rate ({$destinationCountry})", 'rate' => $rate, 'is_default' => false, 'is_active' => true]
        );
    }

    /**
     * Cancel a customer invoice.
     * - Reverses qty_invoiced on linked SO items
     * - Reverses EU OSS accumulation if applicable
     * - Sets status to Cancelled
     */
    public function cancel(CustomerInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice->loadMissing(['items.salesOrderItem']);

            foreach ($invoice->items as $item) {
                if ($item->salesOrderItem) {
                    $item->salesOrderItem->decrement('qty_invoiced', (float) $item->quantity);
                }
            }

            $invoice->loadMissing('partner');
            app(EuOssService::class)->reverseAccumulation($invoice);

            $invoice->update(['status' => DocumentStatus::Cancelled]);
        });
    }
}
