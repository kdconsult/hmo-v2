<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum QuoteStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Sent => __('Sent'),
            self::Accepted => __('Accepted'),
            self::Rejected => __('Rejected'),
            self::Expired => __('Expired'),
            self::Converted => __('Converted'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'primary',
            self::Accepted => 'success',
            self::Rejected => 'danger',
            self::Expired => 'warning',
            self::Converted => 'info',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil->value,
            self::Sent => Heroicon::OutlinedPaperAirplane->value,
            self::Accepted => Heroicon::OutlinedHandThumbUp->value,
            self::Rejected => Heroicon::OutlinedHandThumbDown->value,
            self::Expired => Heroicon::OutlinedClock->value,
            self::Converted => Heroicon::OutlinedArrowPath->value,
        };
    }
}
