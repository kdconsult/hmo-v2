<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FiscalReceiptStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Printed = 'printed';
    case Failed = 'failed';
    case Annulled = 'annulled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Printed => __('Printed'),
            self::Failed => __('Failed'),
            self::Annulled => __('Annulled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Printed => 'success',
            self::Failed => 'danger',
            self::Annulled => 'gray',
        };
    }
}
