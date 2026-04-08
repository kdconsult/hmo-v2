<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum PurchaseOrderStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Sent => __('Sent'),
            self::Confirmed => __('Confirmed'),
            self::PartiallyReceived => __('Partially Received'),
            self::Received => __('Received'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'primary',
            self::Confirmed => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string|Heroicon|null
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil,
            self::Sent => Heroicon::OutlinedPaperAirplane,
            self::Confirmed => Heroicon::OutlinedCheckCircle,
            self::PartiallyReceived => Heroicon::OutlinedInboxArrowDown,
            self::Received => Heroicon::OutlinedCheckBadge,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }
}
