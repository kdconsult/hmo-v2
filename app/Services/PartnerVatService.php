<?php

namespace App\Services;

use App\Enums\VatStatus;
use App\Models\Partner;
use App\Support\EuCountries;

class PartnerVatService
{
    public function __construct(private readonly ViesValidationService $vies) {}

    /**
     * Re-run VIES verification against the partner's stored country_code.
     *
     * Returns the resulting VatStatus after the check.
     */
    public function reVerify(Partner $partner): VatStatus
    {
        $prefix = EuCountries::vatPrefixForCountry($partner->country_code) ?? $partner->country_code;

        // For confirmed partners, extract the suffix from the stored vat_number
        $storedVat = (string) ($partner->vat_number ?? '');
        $vatSuffix = strlen($prefix) > 0 && str_starts_with(strtoupper($storedVat), strtoupper($prefix))
            ? substr($storedVat, strlen($prefix))
            : $storedVat;

        $result = $this->vies->validate($prefix, $vatSuffix);

        $partner->vies_last_checked_at = now();

        if (! $result['available']) {
            // Unavailable: leave current status unchanged, just update the check timestamp
            $partner->save();

            return $partner->vat_status ?? VatStatus::Pending;
        }

        if (! $result['valid']) {
            $partner->is_vat_registered = false;
            $partner->vat_status = VatStatus::NotRegistered;
            $partner->vat_number = null;
            $partner->vies_verified_at = null;
            $partner->save();

            return VatStatus::NotRegistered;
        }

        $confirmedVat = strtoupper($prefix.($result['vat_number'] ?? $vatSuffix));
        $partner->is_vat_registered = true;
        $partner->vat_status = VatStatus::Confirmed;
        $partner->vat_number = $confirmedVat;
        $partner->vies_verified_at = now();
        $partner->save();

        return VatStatus::Confirmed;
    }
}
