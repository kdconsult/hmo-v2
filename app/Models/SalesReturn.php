<?php

namespace App\Models;

use App\Enums\SalesReturnStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SalesReturn extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'sr_number',
        'document_series_id',
        'delivery_note_id',
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
            'status' => SalesReturnStatus::class,
            'returned_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'sr_number'])
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

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'document_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function customerCreditNotes(): HasMany
    {
        return $this->hasMany(CustomerCreditNote::class);
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
        return $this->status === SalesReturnStatus::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this->status === SalesReturnStatus::Confirmed;
    }
}
