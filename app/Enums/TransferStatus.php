<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum TransferStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::InTransit => __('In Transit'),
            self::PartiallyReceived => __('Partially Received'),
            self::Received => __('Received'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InTransit => 'primary',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil->value,
            self::InTransit => Heroicon::OutlinedTruck->value,
            self::PartiallyReceived => Heroicon::OutlinedInboxArrowDown->value,
            self::Received => Heroicon::OutlinedCheckBadge->value,
            self::Cancelled => Heroicon::OutlinedXCircle->value,
        };
    }
}
