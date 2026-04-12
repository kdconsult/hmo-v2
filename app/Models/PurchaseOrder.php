<?php

namespace App\Models;

use App\Enums\PricingMode;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PurchaseOrder extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'po_number',
        'document_series_id',
        'partner_id',
        'warehouse_id',
        'status',
        'currency_code',
        'exchange_rate',
        'pricing_mode',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'expected_delivery_date',
        'notes',
        'internal_notes',
        'ordered_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'pricing_mode' => PricingMode::class,
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'expected_delivery_date' => 'date',
            'ordered_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'po_number', 'partner_id', 'total'])
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

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'document_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceivedNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivedNote::class);
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === PurchaseOrderStatus::Draft;
    }

    public function isFullyReceived(): bool
    {
        return $this->items->every(fn (PurchaseOrderItem $item) => $item->isFullyReceived());
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items->sum(fn (PurchaseOrderItem $item) => (float) $item->line_total);
        $this->tax_amount = $this->items->sum(fn (PurchaseOrderItem $item) => (float) $item->vat_amount);
        $this->total = bcadd(
            bcsub((string) $this->subtotal, (string) $this->discount_amount, 2),
            (string) $this->tax_amount,
            2
        );
        $this->save();
    }

    public function scopeForSupplier($query, int $partnerId): mixed
    {
        return $query->where('partner_id', $partnerId);
    }

    public function scopeWithStatus($query, PurchaseOrderStatus $status): mixed
    {
        return $query->where('status', $status->value);
    }
}
