<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'label',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country_code',
        'is_billing',
        'is_shipping',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_billing' => 'boolean',
            'is_shipping' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
