<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum PaymentGateway: string implements HasColor, HasIcon, HasLabel
{
    case Stripe = 'stripe';
    case BankTransfer = 'bank_transfer';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stripe => __('Stripe'),
            self::BankTransfer => __('Bank Transfer'),
            self::Manual => __('Manual'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Stripe => 'info',
            self::BankTransfer => 'warning',
            self::Manual => 'gray',
        };
    }

    public function getIcon(): string|Heroicon|null
    {
        return match ($this) {
            self::Stripe => Heroicon::OutlinedCreditCard,
            self::BankTransfer => Heroicon::OutlinedBuildingLibrary,
            self::Manual => Heroicon::OutlinedPencilSquare,
        };
    }
}
