<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Enums\VatScenario;
use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteItem;
use App\Models\CustomerInvoice;
use App\Models\VatRate;
use App\Support\TenantVatStatus;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CustomerDebitNoteService
{
    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
    ) {}

    /**
     * Recalculate a single debit note item's VAT and line totals, then save.
     * Requires item->customerDebitNote and item->vatRate to be loaded (or loadable).
     */
    public function recalculateItemTotals(CustomerDebitNoteItem $item): void
    {
        $pricingMode = $item->customerDebitNote->pricing_mode;
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
     * Recalculate a customer debit note's subtotal, tax_amount, and total from its items, then save.
     */
    public function recalculateDocumentTotals(CustomerDebitNote $debitNote): void
    {
        $debitNote->load('items');

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($debitNote->items as $item) {
            $subtotal = bcadd($subtotal, (string) $item->line_total, 2);
            $taxAmount = bcadd($taxAmount, (string) $item->vat_amount, 2);
        }

        $debitNote->subtotal = $subtotal;
        $debitNote->tax_amount = $taxAmount;
        $debitNote->total = bcadd($subtotal, $taxAmount, 2);
        $debitNote->save();
    }

    /**
     * Confirm a debit note with VAT scenario logic (Art. 219 / чл. 115 ЗДДС).
     * Parent-attached: inherit parent's scenario. Standalone: fresh VatScenario::determine().
     *
     * @param  string|null  $subCode  Required for standalone zero-rate + mixed goods/services.
     */
    public function confirmWithScenario(CustomerDebitNote $note, ?string $subCode = null): void
    {
        DB::transaction(function () use ($note, $subCode): void {
            $parent = $note->customerInvoice()->with('partner')->first();

            if ($parent) {
                if ($parent->status !== DocumentStatus::Confirmed) {
                    throw new \DomainException(
                        "Cannot confirm debit note against an unconfirmed parent invoice (#{$parent->invoice_number}, status={$parent->status->value})."
                    );
                }

                if ($note->currency_code !== $parent->currency_code) {
                    throw new \DomainException(
                        "Debit note currency ({$note->currency_code}) must match parent invoice currency ({$parent->currency_code})."
                    );
                }

                $scenario = $parent->vat_scenario;
                $finalSubCode = $parent->vat_scenario_sub_code;
                $isRc = $parent->is_reverse_charge;
            } else {
                // Standalone debit note.
                if (! TenantVatStatus::isRegistered()) {
                    // Non-registered tenant — force Exempt (ЗДДС чл. 113, ал. 9).
                    // Blocks override does NOT apply when a parent exists (F-021).
                    $scenario = VatScenario::Exempt;
                    $finalSubCode = 'default';
                    $isRc = false;
                } else {
                    // Registered tenant — fresh determination.
                    $partner = $note->partner;
                    $tenantCountry = TenantVatStatus::country() ?? 'BG';

                    $scenario = VatScenario::determine(
                        $partner,
                        $tenantCountry,
                        tenantIsVatRegistered: true,
                    );

                    if (in_array($scenario, [VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport], true)) {
                        $itemKind = $this->classifyItems($note);
                        if ($itemKind === 'mixed' && $subCode === null) {
                            throw new \DomainException(
                                'Standalone debit note with mixed goods and services requires an explicit sub_code (goods or services).'
                            );
                        }
                        $finalSubCode = $subCode ?? ($itemKind === 'services' ? 'services' : 'goods');
                    } else {
                        $finalSubCode = $subCode ?? 'default';
                    }

                    $isRc = $scenario === VatScenario::EuB2bReverseCharge;
                }
            }

            if ($scenario?->requiresVatRateChange()) {
                $this->applyZeroRateToItems($note, TenantVatStatus::country() ?? 'BG');
            }

            $this->warnOnLateIssuance($note);

            $note->update([
                'vat_scenario' => $scenario,
                'vat_scenario_sub_code' => $finalSubCode,
                'is_reverse_charge' => $isRc,
                'status' => DocumentStatus::Confirmed,
            ]);

            // OSS positive delta for parent-attached debit notes only; standalone deferred.
            if ($parent) {
                $deltaEur = $this->noteToParentEur($note, $parent);
                app(EuOssService::class)->adjust($parent, $deltaEur);
            }
        });
    }

    /**
     * Confirm a debit note, wrapped in a transaction.
     * Future: update parent invoice balance if applicable.
     */
    public function confirm(CustomerDebitNote $cdn): void
    {
        DB::transaction(function () use ($cdn): void {
            $cdn->update(['status' => DocumentStatus::Confirmed]);
        });
    }

    /**
     * Cancel a debit note, wrapped in a transaction.
     * Future: reverse any balance changes applied on confirm.
     */
    public function cancel(CustomerDebitNote $cdn): void
    {
        DB::transaction(function () use ($cdn): void {
            $cdn->update(['status' => DocumentStatus::Cancelled]);
        });
    }

    private function classifyItems(CustomerDebitNote $note): string
    {
        $types = $note->items
            ->map(fn ($i) => $i->productVariant?->product?->type?->value)
            ->filter()
            ->unique();

        if ($types->count() === 1) {
            return $types->first() === 'service' ? 'services' : 'goods';
        }

        return $types->isEmpty() ? 'goods' : 'mixed';
    }

    private function applyZeroRateToItems(CustomerDebitNote $note, string $tenantCountry): void
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

    private function warnOnLateIssuance(CustomerDebitNote $note): void
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
     * Convert the note's total to EUR using the PARENT's exchange rate.
     */
    private function noteToParentEur(CustomerDebitNote $note, CustomerInvoice $parent): float
    {
        $rate = (float) $parent->exchange_rate;

        return $rate > 0
            ? (float) bcdiv((string) $note->total, (string) $rate, 6)
            : (float) $note->total;
    }
}
