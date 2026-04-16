<?php

namespace App\Enums;

enum VatStatus: string
{
    case NotRegistered = 'not_registered';
    case Confirmed = 'confirmed';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::NotRegistered => 'Not Registered',
            self::Confirmed => 'Confirmed',
            self::Pending => 'Pending Verification',
        };
    }
}
