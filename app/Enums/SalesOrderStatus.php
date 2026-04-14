<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum SalesOrderStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Confirmed => __('Confirmed'),
            self::PartiallyDelivered => __('Partially Delivered'),
            self::Delivered => __('Delivered'),
            self::Invoiced => __('Invoiced'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'gray',
            self::Confirmed => 'info',
            self::PartiallyDelivered => 'warning',
            self::Delivered => 'success',
            self::Invoiced => 'primary',
        };
    }

    public function getIcon(): string|Heroicon|null
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil,
            self::Confirmed => Heroicon::OutlinedCheckCircle,
            self::PartiallyDelivered => Heroicon::OutlinedEllipsisHorizontalCircle,
            self::Delivered => Heroicon::OutlinedCheckBadge,
            self::Invoiced => Heroicon::OutlinedDocumentText,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }
}
