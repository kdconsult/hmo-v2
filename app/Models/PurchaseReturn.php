<?php

namespace App\Models;

use App\Enums\PurchaseReturnStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PurchaseReturn extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'pr_number',
        'document_series_id',
        'goods_received_note_id',
        'partner_id',
        'warehouse_id',
        'status',
        'returned_at',
        'reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseReturnStatus::class,
            'returned_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'pr_number'])
            ->logOnlyDirty();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function goodsReceivedNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class);
    }

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'document_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === PurchaseReturnStatus::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this->status === PurchaseReturnStatus::Confirmed;
    }
}
