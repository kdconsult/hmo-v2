<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends \Stancl\Tenancy\Database\Models\Domain
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
