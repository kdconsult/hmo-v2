<?php

namespace App\Services;

use App\DTOs\ManualOverrideData;
use App\DTOs\PartnerMutationIntent;
use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Enums\VatScenario;
use App\Enums\VatStatus;
use App\Enums\ViesResult;
use App\Events\FiscalReceiptRequested;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\EuCountryVatRate;
use App\Models\Partner;
use App\Models\VatRate;
use App\Support\EuCountries;
use Carbon\Carbon;
use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CustomerInvoiceService
{
    public function __construct(
        private readonly VatCalculationService $vatCalculationService,
        private readonly ViesValidationService $viesValidationService,
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
     * Run a VIES pre-check before invoice confirmation.
     *
     * Determines whether a VIES call is needed and executes it, returning data for the
     * confirmation modal. For valid VIES results, refreshes the partner's stored VAT number
     * and verification timestamp immediately (idempotent, low-risk).
     *
     * VIES is only called when the partner is a cross-border EU partner with a confirmed VAT
     * number. Pending partners without a stored vat_number are silently skipped (treated as B2C).
     *
     * Returns:
     *   - `needed: false`  — no VIES check required (domestic, non-EU, or no VAT number to check)
     *   - `needed: true, result: ViesResult`  — VIES was called; see 'result' key
     *   - `needed: true, result: 'cooldown'`  — called too recently; retry after 'retry_after'
     *
     * For ViesResult::Invalid, the partner mutation is NOT applied here — it is staged as a
     * PartnerMutationIntent and applied atomically inside confirmWithScenario()'s transaction (F-024).
     *
     * @return array{needed: bool, result?: ViesResult|string, partner_mutation?: PartnerMutationIntent, request_id?: string|null, checked_at?: Carbon, retry_after?: Carbon}
     */
    public function runViesPreCheck(CustomerInvoice $invoice): array
    {
        // Non-VAT-registered tenants always use the Exempt scenario — VIES check is irrelevant.
        $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;
        if (! $tenantIsVatRegistered) {
            return ['needed' => false];
        }

        $tenantCountry = CompanySettings::get('company', 'country_code') ?? '';

        $invoice->loadMissing('partner');
        $partner = $invoice->partner;

        if (! $partner) {
            return ['needed' => false];
        }

        // Only re-check cross-border EU partners with confirmed status and a stored vat_number.
        // Pending partners (no stored vat_number) are treated as B2C — no VIES call possible.
        $needsCheck =
            ! empty($partner->country_code)
            && $partner->country_code !== $tenantCountry
            && EuCountries::isEuCountry($partner->country_code)
            && $partner->vat_status === VatStatus::Confirmed
            && ! empty($partner->vat_number);

        if (! $needsCheck) {
            return ['needed' => false];
        }

        // Server-side cooldown: protect against VIES spam.
        // Uses partner.vies_last_checked_at (stored on every check attempt).
        if ($partner->vies_last_checked_at && $partner->vies_last_checked_at->gt(now()->subMinute())) {
            return [
                'needed' => true,
                'result' => 'cooldown',
                'retry_after' => $partner->vies_last_checked_at->addMinute(),
            ];
        }

        // Extract the VAT suffix for the VIES call (strip country prefix from stored vat_number).
        // Note: use country_code (e.g. 'GR') as the VIES countryCode, not the VAT prefix (e.g. 'EL') —
        // they differ for Greece. The prefix is only used for string-stripping, not for the SOAP call.
        $vatPrefix = EuCountries::vatPrefixForCountry($partner->country_code) ?? $partner->country_code;
        $storedVat = (string) $partner->vat_number;
        $vatSuffix = strlen($vatPrefix) > 0 && str_starts_with(strtoupper($storedVat), strtoupper($vatPrefix))
            ? substr($storedVat, strlen($vatPrefix))
            : $storedVat;

        $result = $this->viesValidationService->validate($partner->country_code, $vatSuffix, fresh: true);

        // Always record the attempt timestamp regardless of result
        $partner->vies_last_checked_at = now();
        $partner->save();

        if (! $result['available']) {
            return [
                'needed' => true,
                'result' => ViesResult::Unavailable,
                'partner_mutation' => PartnerMutationIntent::none(),
                'request_id' => null,
                'checked_at' => now(),
            ];
        }

        if (! $result['valid']) {
            // Stage downgrade intent — NOT applied here. Applied atomically inside confirmWithScenario() tx (F-024).
            return [
                'needed' => true,
                'result' => ViesResult::Invalid,
                'partner_mutation' => PartnerMutationIntent::downgrade('vies_invalid_at_invoice_confirmation'),
                'request_id' => $result['request_id'],
                'checked_at' => now(),
            ];
        }

        // Valid: refresh partner confirmation (idempotent, low-risk — stays outside tx)
        $partner->vat_status = VatStatus::Confirmed;
        $partner->is_vat_registered = true;
        $partner->vat_number = strtoupper($vatPrefix.($result['vat_number'] ?? $vatSuffix));
        $partner->vies_verified_at = now();
        $partner->save();

        return [
            'needed' => true,
            'result' => ViesResult::Valid,
            'partner_mutation' => PartnerMutationIntent::none(),
            'request_id' => $result['request_id'],
            'checked_at' => now(),
        ];
    }

    /**
     * Confirm a customer invoice with full VAT audit trail.
     *
     * This is the primary confirmation method. Stores the determined VAT scenario,
     * VIES reference data, and optional manual override audit trail on the invoice.
     *
     * @param  array|null  $viesData  Result from runViesPreCheck(); null if no VIES check ran
     * @param  bool  $treatAsB2c  When true, partner VAT data is ignored (B2C path)
     * @param  ManualOverrideData|null  $override  When set, stores reverse charge manual override audit trail
     * @param  bool  $isDomesticExempt  When true, force VatScenario::DomesticExempt and apply 0% rate under the given sub_code
     * @param  ?string  $subCode  Required when $isDomesticExempt is true; identifies the ЗДДС article (e.g. 'art_45')
     *
     * @throws DomainException when an item would over-invoice its SO line
     */
    public function confirmWithScenario(
        CustomerInvoice $invoice,
        ?array $viesData = null,
        bool $treatAsB2c = false,
        ?ManualOverrideData $override = null,
        bool $isDomesticExempt = false,
        ?string $subCode = null,
    ): void {
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

        // DomesticExempt input validation.
        if ($isDomesticExempt && empty($subCode)) {
            throw new DomainException('DomesticExempt confirmation requires a sub_code.');
        }

        // F-028: 5-day issuance rule warning (non-blocking).
        $supplied = $invoice->supplied_at ?? $invoice->issued_at;
        if ($invoice->issued_at && $supplied && $invoice->issued_at->diffInDays($supplied) > 5) {
            Notification::make()
                ->title(__('invoice-form.late_issuance_title'))
                ->body(__('invoice-form.late_issuance_body'))
                ->warning()
                ->send();
        }

        // Store VIES audit data on the invoice
        if ($viesData && isset($viesData['result']) && $viesData['result'] instanceof ViesResult) {
            $invoice->vies_result = $viesData['result'];
            $invoice->vies_request_id = $viesData['request_id'] ?? null;
            $invoice->vies_checked_at = $viesData['checked_at'] ?? null;
        }

        // Store manual override audit trail
        if ($override !== null) {
            $invoice->reverse_charge_manual_override = true;
            $invoice->reverse_charge_override_user_id = $override->userId;
            $invoice->reverse_charge_override_at = now();
            $invoice->reverse_charge_override_reason = $override->reason;
        }

        $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;

        DB::transaction(function () use ($invoice, $viesData, $treatAsB2c, $tenantIsVatRegistered, $isDomesticExempt, $subCode): void {
            // Apply staged partner mutation first so scenario determination sees the updated state (F-024).
            $mutationIntent = $viesData['partner_mutation'] ?? null;
            if ($mutationIntent instanceof PartnerMutationIntent && $mutationIntent->downgradeToNotRegistered) {
                $invoice->loadMissing('partner');
                $this->applyPartnerDowngrade($invoice->partner, $mutationIntent->reason ?? 'vies_invalid', $invoice);
            }
            if ($isDomesticExempt) {
                $tenantCountry = CompanySettings::get('company', 'country_code');
                if (empty($tenantCountry)) {
                    throw new DomainException('Company country code is not configured.');
                }
                $invoice->vat_scenario = VatScenario::DomesticExempt;
                $invoice->vat_scenario_sub_code = $subCode;
                $invoice->is_reverse_charge = false;

                $targetRate = $this->resolveZeroVatRate($tenantCountry);
                $invoice->loadMissing('items');
                foreach ($invoice->items as $item) {
                    $item->vat_rate_id = $targetRate->id;
                    $item->save();
                    $item->setRelation('customerInvoice', $invoice);
                    $item->setRelation('vatRate', $targetRate);
                    $this->recalculateItemTotals($item);
                }
                $this->recalculateDocumentTotals($invoice);
            } else {
                $this->determineVatType($invoice, $treatAsB2c, $tenantIsVatRegistered);
                $invoice->vat_scenario_sub_code = $this->resolveSubCode($invoice);
            }

            $invoice->status = DocumentStatus::Confirmed;
            $invoice->save();

            if ($invoice->sales_order_id) {
                $so = $invoice->salesOrder;

                app(SalesOrderService::class)->updateInvoicedQuantities($so);

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

                    $soItem->refresh();
                    $soItem->qty_delivered = $soItem->qty_invoiced;
                    $soItem->save();
                }

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

        // Skip OSS accumulation for Exempt and DomesticExempt scenarios — no cross-border B2C accumulation applies
        $invoice->loadMissing('partner');
        if (! in_array($invoice->vat_scenario, [VatScenario::Exempt, VatScenario::DomesticExempt], true)) {
            app(EuOssService::class)->accumulate($invoice);
        }

        $this->pinDocumentData($invoice);
    }

    /**
     * Confirm a customer invoice.
     * Thin wrapper around confirmWithScenario() for backward compatibility.
     *
     * @param  bool  $treatAsB2c  When true, partner VAT data is ignored (B2C path).
     *
     * @throws DomainException when an item would over-invoice its SO line
     */
    public function confirm(CustomerInvoice $invoice, bool $treatAsB2c = false): void
    {
        $this->confirmWithScenario($invoice, treatAsB2c: $treatAsB2c);
    }

    /**
     * Determine the correct EU VAT treatment for an invoice and apply it.
     * Called inside the confirm transaction before status is set to Confirmed.
     *
     * Stores vat_scenario on the invoice. For scenarios requiring a VAT rate change,
     * resolves the target rate, updates all items, and recalculates totals.
     *
     * @throws DomainException when company country code is not configured
     * @throws DomainException when OSS destination country has no VAT rate record
     */
    private function determineVatType(CustomerInvoice $invoice, bool $treatAsB2c = false, bool $tenantIsVatRegistered = true): void
    {
        $tenantCountry = CompanySettings::get('company', 'country_code');

        if (empty($tenantCountry)) {
            throw new DomainException('Company country code is not configured. Please set it in Company Settings.');
        }

        $invoice->loadMissing('partner');
        $partner = $invoice->partner;

        $scenario = VatScenario::determine(
            $partner,
            $tenantCountry,
            ignorePartnerVat: $treatAsB2c,
            tenantIsVatRegistered: $tenantIsVatRegistered,
            year: (int) ($invoice->issued_at?->year ?? now()->year),
        );

        $invoice->vat_scenario = $scenario;

        if (! $scenario->requiresVatRateChange()) {
            $invoice->is_reverse_charge = false;
            $invoice->save();

            return;
        }

        $invoice->is_reverse_charge = ($scenario === VatScenario::EuB2bReverseCharge);

        // Exempt, EuB2bReverseCharge, and NonEuExport all resolve to the tenant's zero-rate.
        $targetVatRate = match ($scenario) {
            VatScenario::Exempt, VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport => $this->resolveZeroVatRate($tenantCountry),
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
     * Used for EU B2B reverse charge, non-EU exports, and Exempt scenarios.
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
     * - Reverses EU OSS accumulation if applicable (skipped for Exempt scenario)
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

            // Skip OSS reversal for Exempt scenario — accumulation was never done
            if ($invoice->vat_scenario !== VatScenario::Exempt) {
                app(EuOssService::class)->reverseAccumulation($invoice);
            }

            $invoice->update(['status' => DocumentStatus::Cancelled]);
        });
    }

    /**
     * F-023 helper: would this invoice resolve to EU B2B reverse-charge given current state?
     * Used to short-circuit confirmation when the tenant has no VAT number configured.
     */
    private function wouldBecomeReverseCharge(CustomerInvoice $invoice, bool $treatAsB2c): bool
    {
        if ($treatAsB2c) {
            return false;
        }

        $tenantCountry = CompanySettings::get('company', 'country_code');
        if (empty($tenantCountry)) {
            return false;
        }

        $invoice->loadMissing('partner');

        try {
            $scenario = VatScenario::determine(
                $invoice->partner,
                $tenantCountry,
                ignorePartnerVat: false,
                tenantIsVatRegistered: (bool) tenancy()->tenant?->is_vat_registered,
                year: (int) ($invoice->issued_at?->year ?? now()->year),
            );
        } catch (DomainException) {
            return false;
        }

        return $scenario === VatScenario::EuB2bReverseCharge;
    }

    /**
     * Resolve the sub_code for the current invoice's vat_scenario.
     * Returns null when no sub_code applies (domestic / B2C / OSS scenarios).
     */
    private function resolveSubCode(CustomerInvoice $invoice): ?string
    {
        return match ($invoice->vat_scenario) {
            VatScenario::Exempt => 'default',
            VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport => $this->inferGoodsOrServices($invoice),
            default => null,
        };
    }

    /**
     * Downgrade a partner to not-VAT-registered, log the activity, and notify the user.
     * Must be called inside a DB transaction (F-024).
     */
    private function applyPartnerDowngrade(Partner $partner, string $reason, CustomerInvoice $invoice): void
    {
        $partner->vat_status = VatStatus::NotRegistered;
        $partner->is_vat_registered = false;
        $partner->vat_number = null;
        $partner->vies_verified_at = null;
        $partner->save();

        activity()
            ->performedOn($partner)
            ->causedBy(auth()->user())
            ->withProperties([
                'reason' => $reason,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'checked_at' => now()->toIso8601String(),
            ])
            ->log('Partner VAT downgraded to not_registered by VIES rejection');

        Notification::make()
            ->title('Partner VAT downgraded')
            ->body("Partner '{$partner->company_name}' is no longer VAT-registered per VIES. Reverse charge will not apply.")
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * Heuristic: classify an invoice as 'goods' or 'services' for sub_code selection.
     * Returns 'services' only when every line is a Service product; otherwise 'goods' (BG SME majority assumption).
     */
    private function inferGoodsOrServices(CustomerInvoice $invoice): string
    {
        $invoice->loadMissing('items.productVariant.product');

        $types = $invoice->items
            ->map(fn ($i) => $i->productVariant?->product?->type)
            ->unique()
            ->filter();

        if ($types->count() === 1 && $types->first() === ProductType::Service) {
            return 'services';
        }

        return 'goods';
    }

    private function pinDocumentData(CustomerInvoice $invoice): void
    {
        $invoice->refresh()->loadMissing('items');

        $source = DocumentHasher::resolveExchangeRateSource(
            $invoice->currency_code,
            $invoice->issued_at ?? now(),
        );

        $invoice->update([
            'exchange_rate_source' => $source,
            'document_hash' => DocumentHasher::forInvoice($invoice),
        ]);
    }
}
