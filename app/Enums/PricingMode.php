<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PricingMode: string implements HasLabel
{
    case VatExclusive = 'vat_exclusive';
    case VatInclusive = 'vat_inclusive';

    public function getLabel(): string
    {
        return match ($this) {
            self::VatExclusive => __('VAT Exclusive'),
            self::VatInclusive => __('VAT Inclusive'),
        };
    }
}
