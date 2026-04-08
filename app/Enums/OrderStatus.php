<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum OrderStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Confirmed => __('Confirmed'),
            self::InProgress => __('In Progress'),
            self::PartiallyFulfilled => __('Partially Fulfilled'),
            self::Fulfilled => __('Fulfilled'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Confirmed => 'info',
            self::InProgress => 'primary',
            self::PartiallyFulfilled => 'warning',
            self::Fulfilled => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string|Heroicon|null
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil,
            self::Confirmed => Heroicon::OutlinedCheckCircle,
            self::InProgress => Heroicon::OutlinedArrowPath,
            self::PartiallyFulfilled => Heroicon::OutlinedEllipsisHorizontalCircle,
            self::Fulfilled => Heroicon::OutlinedCheckBadge,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }
}
