<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CountType: string implements HasLabel
{
    case Full = 'full';
    case Cycle = 'cycle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Full => __('Full Count'),
            self::Cycle => __('Cycle Count'),
        };
    }
}
