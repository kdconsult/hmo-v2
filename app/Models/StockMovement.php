<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_variant_id',
        'warehouse_id',
        'stock_location_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'notes',
        'moved_at',
        'moved_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'decimal:4',
            'moved_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new RuntimeException('Stock movements are immutable.');
        });

        static::deleting(function () {
            throw new RuntimeException('Stock movements are immutable.');
        });
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
