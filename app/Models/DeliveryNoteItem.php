<?php

namespace App\Models;

use App\Enums\SalesReturnStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryNoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_note_id',
        'sales_order_item_id',
        'product_variant_id',
        'quantity',
        'unit_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function salesReturnItems(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function returnedQuantity(): string
    {
        return (string) $this->salesReturnItems()
            ->whereHas('salesReturn', fn ($q) => $q->where('status', '!=', SalesReturnStatus::Cancelled->value))
            ->sum('quantity');
    }

    public function remainingReturnableQuantity(): string
    {
        return bcsub((string) $this->quantity, $this->returnedQuantity(), 4);
    }
}
