<?php

namespace App\Services;

use App\Models\Tenant;

class CompanyVatService
{
    /**
     * Update VAT registration state on the tenant.
     *
     * Enforces the invariant: is_vat_registered = true ↔ vat_number IS NOT NULL.
     *
     * @param array{
     *   is_vat_registered: bool,
     *   vat_number: ?string,
     *   country_code: string,
     * } $data
     */
    public function updateVatRegistration(Tenant $tenant, array $data): void
    {
        if ($data['is_vat_registered'] && blank($data['vat_number'])) {
            throw new \InvalidArgumentException('VAT number is required when is_vat_registered is true.');
        }

        $tenant->is_vat_registered = $data['is_vat_registered'];
        $tenant->country_code = $data['country_code'];

        if (! $data['is_vat_registered']) {
            $tenant->vat_number = null;
            $tenant->vies_verified_at = null;
        } else {
            $tenant->vat_number = $data['vat_number'];
            $tenant->vies_verified_at = now();
        }

        $tenant->save();
    }
}
