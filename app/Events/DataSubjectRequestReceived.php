<?php

namespace App\Events;

use App\Models\Partner;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DataSubjectRequestReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Partner $partner,
        public readonly int $requestedByUserId,
    ) {}
}
