<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NomenclatureType: string implements HasColor, HasLabel
{
    case Stock = 'stock';
    case Service = 'service';
    case Virtual = 'virtual';
    case Bundle = 'bundle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stock => __('Stock Item'),
            self::Service => __('Service'),
            self::Virtual => __('Virtual'),
            self::Bundle => __('Bundle'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Stock => 'primary',
            self::Service => 'success',
            self::Virtual => 'info',
            self::Bundle => 'warning',
        };
    }
}
