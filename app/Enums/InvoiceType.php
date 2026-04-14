<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvoiceType: string implements HasLabel
{
    case Standard = 'standard';
    case Advance = 'advance';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => __('Standard'),
            self::Advance => __('Advance'),
        };
    }
}
