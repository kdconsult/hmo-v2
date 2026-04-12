<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Discontinued = 'discontinued';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Active => __('Active'),
            self::Discontinued => __('Discontinued'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Discontinued => 'danger',
        };
    }
}
