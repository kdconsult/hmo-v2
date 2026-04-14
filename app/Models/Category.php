<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Translatable\HasTranslations;

class Category extends Model
{
    use HasFactory;
    use HasTranslations;
    use SoftDeletes;

    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'description',
        'is_active',
        'default_vat_rate_id',
        'default_unit_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Category $model) {
            if (empty($model->slug) || $model->isDirty('name')) {
                $name = is_array($model->name) ? ($model->name['en'] ?? reset($model->name)) : $model->name;
                $model->slug = Str::slug($name);
            }

            if ($model->parent_id !== null) {
                $depth = $model->computeDepth();
                if ($depth > 2) {
                    throw new InvalidArgumentException('Category nesting cannot exceed 3 levels (depth 0-2).');
                }
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function defaultVatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class, 'default_vat_rate_id');
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithChildren($query)
    {
        return $query->with('children');
    }

    public function depthLevel(): int
    {
        $depth = 0;
        $category = $this;

        while ($category->parent_id !== null) {
            $depth++;
            $category = $category->parent;
        }

        return $depth;
    }

    protected function computeDepth(): int
    {
        $depth = 0;
        $parentId = $this->parent_id;

        while ($parentId !== null) {
            $depth++;
            $parent = Category::find($parentId);
            $parentId = $parent?->parent_id;

            if ($depth > 3) {
                break;
            }
        }

        return $depth;
    }

    /**
     * Resolve a default attribute by walking up the parent chain.
     * Returns the first non-null value found, or null.
     *
     * Caller should eager-load parent.parent to avoid N+1 queries.
     *
     * @param  string  $attribute  One of: default_vat_rate_id, default_unit_id
     */
    public function resolveDefault(string $attribute): mixed
    {
        $allowed = ['default_vat_rate_id', 'default_unit_id'];

        if (! in_array($attribute, $allowed, true)) {
            throw new InvalidArgumentException("Cannot resolve default for attribute: {$attribute}");
        }

        $category = $this;

        while ($category !== null) {
            if ($category->{$attribute} !== null) {
                return $category->{$attribute};
            }
            $category = $category->parent;
        }

        return null;
    }
}
