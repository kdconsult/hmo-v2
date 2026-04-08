<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PartnerType: string implements HasColor, HasLabel
{
    case Individual = 'individual';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => __('Individual'),
            self::Company => __('Company'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Individual => 'info',
            self::Company => 'primary',
        };
    }
}
