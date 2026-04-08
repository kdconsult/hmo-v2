<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum DocumentStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Sent = 'sent';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Confirmed => __('Confirmed'),
            self::Sent => __('Sent'),
            self::PartiallyPaid => __('Partially Paid'),
            self::Paid => __('Paid'),
            self::Overdue => __('Overdue'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Confirmed => 'info',
            self::Sent => 'primary',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil->value,
            self::Confirmed => Heroicon::OutlinedCheckCircle->value,
            self::Sent => Heroicon::OutlinedPaperAirplane->value,
            self::PartiallyPaid => Heroicon::OutlinedBanknotes->value,
            self::Paid => Heroicon::OutlinedCheckBadge->value,
            self::Overdue => Heroicon::OutlinedExclamationCircle->value,
            self::Cancelled => Heroicon::OutlinedXCircle->value,
        };
    }
}
