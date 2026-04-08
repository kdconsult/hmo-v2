<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BankTransactionType: string implements HasLabel
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Credit => __('Credit'),
            self::Debit => __('Debit'),
        };
    }
}
