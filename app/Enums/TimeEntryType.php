<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TimeEntryType: string implements HasLabel
{
    case Manual = 'manual';
    case Timer = 'timer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => __('Manual'),
            self::Timer => __('Timer'),
        };
    }
}
