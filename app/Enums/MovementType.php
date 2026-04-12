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
    case Opening = 'opening';
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
            self::Opening => __('Opening'),
            self::InitialStock => __('Initial Stock'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Purchase, self::TransferIn, self::Return, self::InitialStock, self::Opening => 'success',
            self::Sale, self::TransferOut => 'danger',
            self::Adjustment => 'warning',
        };
    }
}
