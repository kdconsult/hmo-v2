<?php

namespace App\Enums;

enum ReverseChargeOverrideReason: string
{
    case ViesUnavailable = 'vies_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::ViesUnavailable => 'VIES Unavailable',
        };
    }
}
