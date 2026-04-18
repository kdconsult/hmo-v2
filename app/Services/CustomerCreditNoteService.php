<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteItem;
use App\Models\CustomerInvoice;
use App\Models\VatRate;
use App\Support\TenantVatStatus;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CustomerCreditNoteService
{
    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
    ) {}

    /**
     * Recalculate a single credit note item's VAT and line totals, then save.
     * Requires item->customerCreditNote and item->vatRate to be loaded (or loadable).
     */
    public function recalculateItemTotals(CustomerCreditNoteItem $item): void
    {
        $pricingMode = $item->customerCreditNote->pricing_mode;
        $vatRate = (float) $item->vatRate->rate;

        $base = bcmul((string) $item->quantity, (string) $item->unit_price, 4);

        $result = match ($pricingMode) {
            PricingMode::VatExclusive => $this->vatCalculationService->fromNet((float) $base, $vatRate),
            PricingMode::VatInclusive => $this->vatCalculationService->fromGross((float) $base, $vatRate),
        };

        $item->vat_amount = number_format($result['vat'], 2, '.', '');
        $item->line_total = number_format($result['net'], 2, '.', '');
        $item->line_total_with_vat = number_format($result['gross'], 2, '.', '');
        $item->save();
    }

    /**
     * Recalculate a customer credit note's subtotal, tax_amount, and total from its items, then save.
     */
    public function recalculateDocumentTotals(CustomerCreditNote $creditNote): void
    {
        $creditNote->load('items');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($creditNote->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $taxAmount = bcadd($taxAmount, (string) $item->vat_amount, 2);
        }

        $creditNote->subtotal = $subtotal;
        $creditNote->tax_amount = $taxAmount;
        $creditNote->total = bcadd($subtotal, $taxAmount, 2);
        $creditNote->save();
    }

    /**
     * Confirm a credit note, inheriting the parent invoice's VAT scenario (Art. 219 / чл. 115 ЗДДС).
     * Parent must be Confirmed. Currency must match parent.
     */
    public function confirmWithScenario(CustomerCreditNote $note): void
    {
        DB::transaction(function () use ($note): void {
            // Credit notes always have a parent (schema: customer_invoice_id NOT NULL).
            // Inheritance wins unconditionally — tenant's current VAT status does NOT override (F-021,
            // Art. 90 Directive 2006/112/EC: correction mirrors original supply's taxable basis).
            $parent = $note->customerInvoice()->with('partner')->first();

            if (! $parent) {
                throw new \DomainException('Credit note has no parent invoice.');
            }

            if ($parent->status !== DocumentStatus::Confirmed) {
                throw new \DomainException(
                    "Cannot confirm credit note against an unconfirmed parent invoice (#{$parent->invoice_number}, status={$parent->status->value})."
                );
            }

            if ($note->currency_code !== $parent->currency_code) {
                throw new \DomainException(
                    "Credit note currency ({$note->currency_code}) must match parent invoice currency ({$parent->currency_code})."
                );
            }

            $scenario = $parent->vat_scenario;
            $subCode = $parent->vat_scenario_sub_code;
            $isRc = $parent->is_reverse_charge;

            if ($scenario?->requiresVatRateChange()) {
                $this->applyZeroRateToItems($note, TenantVatStatus::country() ?? 'BG');
            }

            $this->warnOnLateIssuance($note);

            $note->update([
                'vat_scenario' => $scenario,
                'vat_scenario_sub_code' => $subCode,
                'is_reverse_charge' => $isRc,
                'status' => DocumentStatus::Confirmed,
            ]);

            $deltaEur = -1.0 * $this->noteToParentEur($note, $parent);
            app(EuOssService::class)->adjust($parent, $deltaEur);
        });

        $this->pinDocumentData($note->refresh());
    }

    /**
     * Confirm a credit note, wrapped in a transaction.
     * Future: update parent invoice balance, adjust OSS if applicable.
     */
    public function confirm(CustomerCreditNote $ccn): void
    {
        DB::transaction(function () use ($ccn): void {
            $ccn->update(['status' => DocumentStatus::Confirmed]);
        });
    }

    /**
     * Cancel a credit note, wrapped in a transaction.
     * Future: reverse any balance or OSS changes applied on confirm.
     */
    public function cancel(CustomerCreditNote $ccn): void
    {
        DB::transaction(function () use ($ccn): void {
            $ccn->update(['status' => DocumentStatus::Cancelled]);
        });
    }

    private function applyZeroRateToItems(CustomerCreditNote $note, string $tenantCountry): void
    {
        $zero = VatRate::where('country_code', $tenantCountry)
            ->where('rate', 0)
            ->first() ?? TenantVatStatus::zeroExemptRate();

        foreach ($note->items as $item) {
            $item->update(['vat_rate_id' => $zero->id]);
            $this->recalculateItemTotals($item->fresh());
        }

        $this->recalculateDocumentTotals($note->fresh());
    }

    private function warnOnLateIssuance(CustomerCreditNote $note): void
    {
        $trigger = $note->triggering_event_date ?? $note->issued_at;
        if ($trigger && $note->issued_at && $trigger->diffInDays($note->issued_at, false) > 5) {
            Notification::make()
                ->title(__('invoice-form.note_late_issuance_title'))
                ->body(__('invoice-form.note_late_issuance_body'))
                ->warning()
                ->send();
        }
    }

    /**
     * Convert the note's total to EUR using the PARENT's exchange rate (not the note's own).
     * Both documents must be in the same currency (asserted in confirmWithScenario).
     */
    private function noteToParentEur(CustomerCreditNote $note, CustomerInvoice $parent): float
    {
        $rate = (float) $parent->exchange_rate;

        return $rate > 0
            ? (float) bcdiv((string) $note->total, (string) $rate, 6)
            : (float) $note->total;
    }

    private function pinDocumentData(CustomerCreditNote $note): void
    {
        $note->loadMissing('items');
        $parent = CustomerInvoice::find($note->customer_invoice_id);

        $source = DocumentHasher::resolveExchangeRateSource(
            $note->currency_code,
            $note->issued_at ?? now(),
        );

        $note->update([
            'exchange_rate_source' => $source,
            'document_hash' => DocumentHasher::forCreditNote($note, $parent),
        ]);
    }
}
