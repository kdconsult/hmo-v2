<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class ProductVariant extends Model
{
    use HasFactory;
    use HasTranslations;
    use SoftDeletes;

    public array $translatable = ['name'];

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'purchase_price',
        'sale_price',
        'barcode',
        'is_default',
        'is_active',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'attributes' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function effectivePurchasePrice(): ?string
    {
        return $this->purchase_price ?? $this->product->purchase_price;
    }

    public function effectiveSalePrice(): ?string
    {
        return $this->sale_price ?? $this->product->sale_price;
    }
}
