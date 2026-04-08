<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InventoryCountStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Approved = 'approved';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::InProgress => __('In Progress'),
            self::Completed => __('Completed'),
            self::Approved => __('Approved'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InProgress => 'primary',
            self::Completed => 'warning',
            self::Approved => 'success',
        };
    }
}
