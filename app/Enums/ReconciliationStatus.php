<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReconciliationStatus: string implements HasColor, HasLabel
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case PartiallyMatched = 'partially_matched';
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unmatched => __('Unmatched'),
            self::Matched => __('Matched'),
            self::PartiallyMatched => __('Partially Matched'),
            self::Ignored => __('Ignored'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Unmatched => 'danger',
            self::Matched => 'success',
            self::PartiallyMatched => 'warning',
            self::Ignored => 'gray',
        };
    }
}
