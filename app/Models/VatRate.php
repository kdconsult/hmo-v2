<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VatRate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'country_code',
        'name',
        'rate',
        'type',
        'is_default',
        'is_active',
        'sort_order',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function scopeActive($query): mixed
    {
        return $query->where('is_active', true);
    }

    public function scopeForCountry($query, string $countryCode): mixed
    {
        return $query->where('country_code', $countryCode);
    }
}
