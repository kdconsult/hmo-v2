<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum PaymentMethod: string implements HasIcon, HasLabel
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case DirectDebit = 'direct_debit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::BankTransfer => __('Bank Transfer'),
            self::Card => __('Card'),
            self::DirectDebit => __('Direct Debit'),
        };
    }

    public function getIcon(): string|Heroicon|null
    {
        return match ($this) {
            self::Cash => Heroicon::OutlinedBanknotes,
            self::BankTransfer => Heroicon::OutlinedBuildingLibrary,
            self::Card => Heroicon::OutlinedCreditCard,
            self::DirectDebit => Heroicon::OutlinedArrowPathRoundedSquare,
        };
    }
}
