<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CreditNoteReason: string implements HasLabel
{
    case Return = 'return';
    case Discount = 'discount';
    case Error = 'error';
    case Damaged = 'damaged';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Return => __('Return'),
            self::Discount => __('Discount'),
            self::Error => __('Error'),
            self::Damaged => __('Damaged'),
            self::Other => __('Other'),
        };
    }
}
