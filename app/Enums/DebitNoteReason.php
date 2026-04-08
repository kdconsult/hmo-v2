<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DebitNoteReason: string implements HasLabel
{
    case PriceIncrease = 'price_increase';
    case AdditionalCharge = 'additional_charge';
    case Error = 'error';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::PriceIncrease => __('Price Increase'),
            self::AdditionalCharge => __('Additional Charge'),
            self::Error => __('Error'),
            self::Other => __('Other'),
        };
    }
}
