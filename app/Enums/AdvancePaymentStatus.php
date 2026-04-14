<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AdvancePaymentStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case PartiallyApplied = 'partially_applied';
    case FullyApplied = 'fully_applied';
    case Refunded = 'refunded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::PartiallyApplied => __('Partially Applied'),
            self::FullyApplied => __('Fully Applied'),
            self::Refunded => __('Refunded'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Open => 'info',
            self::PartiallyApplied => 'warning',
            self::FullyApplied => 'success',
            self::Refunded => 'danger',
        };
    }
}
