<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasFactory;
    use HasTranslations;
    use LogsActivity;
    use SoftDeletes;

    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'category_id',
        'unit_id',
        'purchase_price',
        'sale_price',
        'vat_rate_id',
        'status',
        'is_stockable',
        'barcode',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'purchase_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'is_stockable' => 'boolean',
            'attributes' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'type', 'status', 'sale_price'])
            ->logOnlyDirty();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Product $model) {
            if (! $model->isDirty('is_stockable')) {
                $model->is_stockable = $model->type !== ProductType::Service;
            }

            if (! $model->isDirty('status')) {
                $model->status = ProductStatus::Active;
            }
        });

        static::created(function (Product $model) {
            $raw = $model->getRawOriginal('name') ?? $model->getAttributes()['name'];
            $nameArray = is_string($raw) ? (json_decode($raw, true) ?? [$raw]) : $raw;

            $model->variants()->create([
                'name' => $nameArray,
                'sku' => $model->code,
                'is_default' => true,
                'is_active' => true,
            ]);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', ProductStatus::Active);
    }

    public function hasVariants(): bool
    {
        return $this->variants()
            ->where('is_default', false)
            ->where('is_active', true)
            ->exists();
    }
}
