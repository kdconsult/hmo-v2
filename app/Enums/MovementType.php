<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MovementType: string implements HasColor, HasLabel
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Adjustment = 'adjustment';
    case Return = 'return';
    case InternalConsumption = 'internal_consumption';
    case Production = 'production';
    case InitialStock = 'initial_stock';

    public function getLabel(): string
    {
        return match ($this) {
            self::Purchase => __('Purchase'),
            self::Sale => __('Sale'),
            self::TransferOut => __('Transfer Out'),
            self::TransferIn => __('Transfer In'),
            self::Adjustment => __('Adjustment'),
            self::Return => __('Return'),
            self::InternalConsumption => __('Internal Consumption'),
            self::Production => __('Production'),
            self::InitialStock => __('Initial Stock'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Purchase, self::TransferIn, self::Return, self::InitialStock => 'success',
            self::Sale, self::TransferOut, self::InternalConsumption => 'danger',
            self::Adjustment, self::Production => 'warning',
        };
    }
}
