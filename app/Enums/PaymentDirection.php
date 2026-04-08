<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentDirection: string implements HasLabel
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Incoming => __('Incoming'),
            self::Outgoing => __('Outgoing'),
        };
    }
}
