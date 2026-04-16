<?php

namespace App\Enums;

enum ViesResult: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Invalid => 'Invalid',
            self::Unavailable => 'Unavailable',
        };
    }
}
