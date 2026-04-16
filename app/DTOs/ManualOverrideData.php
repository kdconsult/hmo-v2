<?php

namespace App\DTOs;

use App\Enums\ReverseChargeOverrideReason;

readonly class ManualOverrideData
{
    public function __construct(
        public int $userId,
        public ReverseChargeOverrideReason $reason,
    ) {}
}
