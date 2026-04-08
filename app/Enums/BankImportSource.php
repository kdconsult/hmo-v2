<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BankImportSource: string implements HasLabel
{
    case Csv = 'csv';
    case Camt053 = 'camt053';
    case Api = 'api';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Csv => __('CSV Upload'),
            self::Camt053 => __('CAMT.053'),
            self::Api => __('API'),
            self::Manual => __('Manual Entry'),
        };
    }
}
