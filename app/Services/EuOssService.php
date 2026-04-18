<?php

namespace App\Services;

use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Models\EuCountryVatRate;
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Support\EuCountries;

class EuOssService
{
    /**
     * Determine if EU OSS (One-Stop Shop) VAT rules should apply to a transaction.
     * Applies when: partner is B2C (no valid EU VAT), cross-border within EU, AND threshold exceeded.
     */
    public function shouldApplyOss(Partner $partner): bool
    {
        if ($partner->hasValidEuVat()) {
            return false; // B2B — reverse charge rules apply instead
        }

        if (empty($partner->country_code)) {
            return false;
        }

        if (! in_array($partner->country_code, EuCountries::codes(), true)) {
            return false; // Non-EU
        }

        $tenantCountry = CompanySettings::get('company', 'country_code');
        if ($partner->country_code === $tenantCountry) {
            return false; // Domestic sale
        }

        return EuOssAccumulation::isThresholdExceeded((int) now()->year);
    }

    /**
     * Accumulate the invoice total (converted to EUR) for EU OSS threshold tracking.
     * Only accumulates for cross-border B2C EU transactions.
     */
    public function accumulate(CustomerInvoice $invoice): void
    {
        $partner = $invoice->partner;

        if (! $partner || empty($partner->country_code)) {
            return;
        }

        if (! in_array($partner->country_code, EuCountries::codes(), true)) {
            return; // Not EU
        }

        $tenantCountry = CompanySettings::get('company', 'country_code');
        if ($partner->country_code === $tenantCountry) {
            return; // Domestic
        }

        if ($partner->hasValidEuVat()) {
            return; // B2B — OSS doesn't apply
        }

        // Convert total to EUR using exchange rate
        $totalEur = bccomp((string) $invoice->exchange_rate, '0', 6) > 0
            ? bcdiv((string) $invoice->total, (string) $invoice->exchange_rate, 2)
            : (string) $invoice->total;

        EuOssAccumulation::accumulate(
            $partner->country_code,
            (int) now()->year,
            (float) $totalEur
        );
    }

    /**
     * Reverse the OSS accumulation for a cancelled invoice.
     * Mirrors the same eligibility checks as accumulate() — non-qualifying invoices are silently skipped.
     */
    public function reverseAccumulation(CustomerInvoice $invoice): void
    {
        $partner = $invoice->partner;

        if (! $partner || empty($partner->country_code)) {
            return;
        }

        if (! in_array($partner->country_code, EuCountries::codes(), true)) {
            return;
        }

        $tenantCountry = CompanySettings::get('company', 'country_code');
        if ($partner->country_code === $tenantCountry) {
            return;
        }

        if ($partner->hasValidEuVat()) {
            return;
        }

        $totalEur = bccomp((string) $invoice->exchange_rate, '0', 6) > 0
            ? bcdiv((string) $invoice->total, (string) $invoice->exchange_rate, 2)
            : (string) $invoice->total;

        EuOssAccumulation::accumulate(
            $partner->country_code,
            (int) now()->year,
            -(float) $totalEur
        );
    }

    /**
     * Adjust OSS accumulation for a credit or debit note against a parent invoice.
     * Negative deltaEur for credit notes (reduce); positive for debit notes (add).
     * Uses the parent invoice's year and FX rate so the ledger reconciles with the original accumulation.
     */
    public function adjust(CustomerInvoice $parent, float $deltaEur): void
    {
        $partner = $parent->partner;

        if (! $partner || empty($partner->country_code)) {
            return;
        }

        if (! in_array($partner->country_code, EuCountries::codes(), true)) {
            return;
        }

        $tenantCountry = CompanySettings::get('company', 'country_code');
        if ($partner->country_code === $tenantCountry) {
            return;
        }

        if ($partner->hasValidEuVat()) {
            return;
        }

        $year = (int) ($parent->issued_at?->year ?? now()->year);

        EuOssAccumulation::accumulate(
            $partner->country_code,
            $year,
            $deltaEur
        );
    }

    /**
     * Get the standard VAT rate for a destination EU country.
     */
    public function getDestinationVatRate(string $countryCode): float
    {
        return EuCountryVatRate::getStandardRate($countryCode) ?? 0.0;
    }
}
