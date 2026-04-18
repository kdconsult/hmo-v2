<?php

namespace App\Support;

use App\Models\VatRate;

class TenantVatStatus
{
    public static function isRegistered(): bool
    {
        return (bool) (tenancy()->tenant?->is_vat_registered ?? false);
    }

    public static function country(): ?string
    {
        return tenancy()->tenant?->country_code;
    }

    /**
     * The tenant's 0% exempt rate. Created on demand if missing (defensive; should be seeded).
     */
    public static function zeroExemptRate(): VatRate
    {
        $country = static::country();

        return VatRate::firstOrCreate(
            ['country_code' => $country, 'rate' => 0, 'type' => 'zero'],
            ['name' => '0% — Exempt', 'is_active' => true, 'is_default' => false],
        );
    }
}
