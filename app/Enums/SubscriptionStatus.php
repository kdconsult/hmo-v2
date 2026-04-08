<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum SubscriptionStatus: string implements HasColor, HasIcon, HasLabel
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trial => __('Trial'),
            self::Active => __('Active'),
            self::PastDue => __('Past Due'),
            self::Suspended => __('Suspended'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Trial => 'info',
            self::Active => 'success',
            self::PastDue => 'warning',
            self::Suspended => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string|Heroicon|null
    {
        return match ($this) {
            self::Trial => Heroicon::OutlinedClock,
            self::Active => Heroicon::OutlinedCheckCircle,
            self::PastDue => Heroicon::OutlinedExclamationCircle,
            self::Suspended => Heroicon::OutlinedPause,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }

    public function isAccessible(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }
}
