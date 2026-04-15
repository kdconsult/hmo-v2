<?php

namespace App\Services;

use App\Enums\AdvancePaymentStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\SeriesType;
use App\Events\FiscalReceiptRequested;
use App\Models\AdvancePayment;
use App\Models\AdvancePaymentApplication;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\NumberSeries;
use App\Models\VatRate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdvancePaymentService
{
    public function __construct(
        private readonly CustomerInvoiceService $customerInvoiceService,
    ) {}

    /**
     * Create and confirm an advance invoice (CustomerInvoice with invoice_type=Advance).
     * Links the invoice back to the advance payment via customer_invoice_id.
     *
     * @throws InvalidArgumentException
     */
    public function createAdvanceInvoice(AdvancePayment $ap): CustomerInvoice
    {
        if ($ap->customer_invoice_id) {
            throw new InvalidArgumentException(
                "Advance payment [{$ap->ap_number}] already has an advance invoice."
            );
        }

        $vatRate = VatRate::active()->where('is_default', true)->first()
            ?? VatRate::active()->orderByDesc('rate')->first();

        if (! $vatRate) {
            throw new InvalidArgumentException(
                'No active VAT rate found. Configure VAT rates in Settings before issuing an advance invoice.'
            );
        }

        $series = NumberSeries::getDefault(SeriesType::Invoice);

        if (! $series) {
            throw new InvalidArgumentException(
                'No invoice number series configured. Go to Settings → Number Series and create one.'
            );
        }

        $invoice = DB::transaction(function () use ($ap, $vatRate, $series): CustomerInvoice {
            $invoice = CustomerInvoice::create([
                'invoice_number' => $series->generateNumber(),
                'document_series_id' => $series->id,
                'invoice_type' => InvoiceType::Advance,
                'partner_id' => $ap->partner_id,
                'sales_order_id' => $ap->sales_order_id,
                'status' => DocumentStatus::Confirmed,
                'currency_code' => $ap->currency_code,
                'exchange_rate' => $ap->exchange_rate,
                'pricing_mode' => PricingMode::VatExclusive,
                'payment_method' => $ap->payment_method,
                'issued_at' => $ap->received_at ?? now()->toDateString(),
                'created_by' => $ap->created_by,
                'subtotal' => '0.00',
                'discount_amount' => '0.00',
                'tax_amount' => '0.00',
                'total' => '0.00',
                'amount_paid' => '0.00',
                'amount_due' => '0.00',
            ]);

            $item = CustomerInvoiceItem::create([
                'customer_invoice_id' => $invoice->id,
                'description' => __('Advance payment'),
                'quantity' => '1.0000',
                'unit_price' => $ap->amount,
                'vat_rate_id' => $vatRate->id,
                'discount_percent' => '0.00',
                'discount_amount' => '0.00',
                'vat_amount' => '0.00',
                'line_total' => '0.00',
                'line_total_with_vat' => '0.00',
                'sort_order' => 0,
            ]);

            $item->setRelation('customerInvoice', $invoice);
            $item->setRelation('vatRate', $vatRate);

            $this->customerInvoiceService->recalculateItemTotals($item);
            $this->customerInvoiceService->recalculateDocumentTotals($invoice);

            $ap->customer_invoice_id = $invoice->id;
            $ap->save();

            return $invoice;
        });

        if ($ap->payment_method === PaymentMethod::Cash) {
            FiscalReceiptRequested::dispatch($invoice->fresh());
        }

        return $invoice;
    }

    /**
     * Apply part or all of an advance payment as a negative deduction row on a final invoice.
     * Creates an AdvancePaymentApplication record and updates advance payment status.
     *
     * @throws InvalidArgumentException
     */
    public function applyToFinalInvoice(AdvancePayment $ap, CustomerInvoice $invoice, float|string $amount): AdvancePaymentApplication
    {
        $amount = number_format((float) $amount, 2, '.', '');

        if (in_array($ap->status, [AdvancePaymentStatus::FullyApplied, AdvancePaymentStatus::Refunded])) {
            throw new InvalidArgumentException(
                "Advance payment [{$ap->ap_number}] has status [{$ap->status->getLabel()}] and cannot be applied."
            );
        }

        if (bccomp($amount, $ap->remainingAmount(), 2) > 0) {
            throw new InvalidArgumentException(
                "Amount to apply ({$amount}) exceeds the remaining balance ({$ap->remainingAmount()}) on advance payment [{$ap->ap_number}]."
            );
        }

        // Carry the same vat_rate_id from the advance invoice item for consistent VAT treatment
        $ap->loadMissing('advanceInvoice.items');
        $advanceItem = $ap->advanceInvoice?->items->first();
        $vatRateId = $advanceItem?->vat_rate_id
            ?? VatRate::active()->where('is_default', true)->first()?->id
            ?? VatRate::active()->orderByDesc('rate')->first()?->id;

        if (! $vatRateId) {
            throw new InvalidArgumentException(
                'No active VAT rate found. Cannot apply advance payment deduction.'
            );
        }

        return DB::transaction(function () use ($ap, $invoice, $amount, $vatRateId): AdvancePaymentApplication {
            $application = AdvancePaymentApplication::create([
                'advance_payment_id' => $ap->id,
                'customer_invoice_id' => $invoice->id,
                'amount_applied' => $amount,
                'applied_at' => now(),
            ]);

            $deductionItem = CustomerInvoiceItem::create([
                'customer_invoice_id' => $invoice->id,
                'description' => __('Advance deduction')." — {$ap->ap_number}",
                'quantity' => '-1.0000',
                'unit_price' => $amount,
                'vat_rate_id' => $vatRateId,
                'discount_percent' => '0.00',
                'discount_amount' => '0.00',
                'vat_amount' => '0.00',
                'line_total' => '0.00',
                'line_total_with_vat' => '0.00',
                'sort_order' => 999,
            ]);

            $vatRate = VatRate::find($vatRateId);
            $deductionItem->setRelation('customerInvoice', $invoice);
            $deductionItem->setRelation('vatRate', $vatRate);

            $this->customerInvoiceService->recalculateItemTotals($deductionItem);
            $this->customerInvoiceService->recalculateDocumentTotals($invoice);

            $ap->amount_applied = bcadd((string) $ap->amount_applied, $amount, 2);
            $ap->status = $ap->isFullyApplied()
                ? AdvancePaymentStatus::FullyApplied
                : AdvancePaymentStatus::PartiallyApplied;
            $ap->save();

            return $application;
        });
    }

    /**
     * Refund an advance payment (Open or PartiallyApplied only).
     * Blocked if a confirmed advance invoice already exists for this payment.
     *
     * @throws InvalidArgumentException
     */
    public function refund(AdvancePayment $ap): void
    {
        if (! in_array($ap->status, [AdvancePaymentStatus::Open, AdvancePaymentStatus::PartiallyApplied])) {
            throw new InvalidArgumentException(
                "Cannot refund advance payment [{$ap->ap_number}]: status is [{$ap->status->getLabel()}]."
            );
        }

        if ($ap->advanceInvoice?->status === DocumentStatus::Confirmed) {
            throw new InvalidArgumentException(
                'Cannot refund: a confirmed advance invoice exists. Cancel or credit the invoice first.'
            );
        }

        $ap->status = AdvancePaymentStatus::Refunded;
        $ap->save();
    }

    /**
     * Reverse a previously applied advance payment application.
     * Only allowed when the linked invoice is still in Draft status.
     *
     * @throws InvalidArgumentException
     */
    public function reverseApplication(AdvancePaymentApplication $application): void
    {
        $application->loadMissing(['advancePayment', 'customerInvoice']);

        if ($application->customerInvoice?->status === DocumentStatus::Confirmed) {
            throw new InvalidArgumentException(
                'Cannot reverse application: the linked invoice is already confirmed. Issue a Credit Note instead.'
            );
        }

        DB::transaction(function () use ($application): void {
            $ap = $application->advancePayment;

            $ap->amount_applied = bcsub((string) $ap->amount_applied, (string) $application->amount_applied, 2);

            if (bccomp((string) $ap->amount_applied, '0.00', 2) <= 0) {
                $ap->status = AdvancePaymentStatus::Open;
                $ap->amount_applied = '0.00';
            } else {
                $ap->status = AdvancePaymentStatus::PartiallyApplied;
            }

            $ap->save();

            $application->delete();
        });
    }
}
