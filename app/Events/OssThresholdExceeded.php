<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OssThresholdExceeded
{
    use Dispatchable;

    public function __construct(
        public readonly int $year,
    ) {}
}
