<?php

namespace App\Enums;

use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Support\EuCountries;

enum VatScenario: string
{
    case Domestic = 'domestic';
    case EuB2bReverseCharge = 'eu_b2b_reverse_charge';
    case EuB2cUnderThreshold = 'eu_b2c_under_threshold';
    case EuB2cOverThreshold = 'eu_b2c_over_threshold';
    case NonEuExport = 'non_eu_export';

    /**
     * Determine the correct VAT scenario for a cross-border transaction.
     *
     * Branching order (EU VAT Directive 2006/112/EC):
     * 1. Empty country_code → treat as non-EU export
     * 2. Same country as tenant → domestic
     * 3. Not in EU → export (0%)
     * 4. EU + valid VAT (unless $ignorePartnerVat) → B2B reverse charge (Article 196)
     * 5. EU + no valid VAT + OSS threshold exceeded → B2C OSS rate
     * 6. EU + no valid VAT + threshold not exceeded → B2C domestic rate
     *
     * @param  bool  $ignorePartnerVat  When true, skip the hasValidEuVat() check so the partner
     *                                  is treated as a B2C customer regardless of stored VAT data.
     *                                  Use when VIES has explicitly rejected the VAT number at
     *                                  confirm time and the user chose to proceed with standard VAT.
     */
    public static function determine(Partner $partner, string $tenantCountryCode, bool $ignorePartnerVat = false): self
    {
        if (empty($partner->country_code)) {
            return self::NonEuExport;
        }

        if ($partner->country_code === $tenantCountryCode) {
            return self::Domestic;
        }

        if (! EuCountries::isEuCountry($partner->country_code)) {
            return self::NonEuExport;
        }

        if (! $ignorePartnerVat && $partner->hasValidEuVat()) {
            return self::EuB2bReverseCharge;
        }

        if (EuOssAccumulation::isThresholdExceeded((int) now()->year)) {
            return self::EuB2cOverThreshold;
        }

        return self::EuB2cUnderThreshold;
    }

    public function description(): string
    {
        return match ($this) {
            self::Domestic => 'Domestic sale — standard VAT applies.',
            self::EuB2bReverseCharge => 'EU B2B — reverse charge applies (0% VAT, Article 196).',
            self::EuB2cUnderThreshold => 'EU B2C — below OSS threshold, domestic VAT rate applies.',
            self::EuB2cOverThreshold => 'EU B2C — OSS threshold exceeded, destination country VAT rate applies.',
            self::NonEuExport => 'Non-EU export — zero-rated (0% VAT).',
        };
    }

    /**
     * Whether confirming this invoice should override item VAT rates.
     * False = keep original rates; True = apply scenario-specific rate.
     */
    public function requiresVatRateChange(): bool
    {
        return match ($this) {
            self::Domestic, self::EuB2cUnderThreshold => false,
            self::EuB2bReverseCharge, self::EuB2cOverThreshold, self::NonEuExport => true,
        };
    }
}
