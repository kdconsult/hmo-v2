<?php

namespace App\Models;

use App\Enums\PricingMode;
use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Quotation extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'quotation_number',
        'document_series_id',
        'partner_id',
        'status',
        'currency_code',
        'exchange_rate',
        'pricing_mode',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'valid_until',
        'issued_at',
        'notes',
        'internal_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'pricing_mode' => PricingMode::class,
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'issued_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'quotation_number', 'partner_id', 'total'])
            ->logOnlyDirty();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'document_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === QuotationStatus::Draft;
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items->sum(fn (QuotationItem $item) => (float) $item->line_total);
        $this->tax_amount = $this->items->sum(fn (QuotationItem $item) => (float) $item->vat_amount);
        $this->total = bcadd(
            bcsub((string) $this->subtotal, (string) $this->discount_amount, 2),
            (string) $this->tax_amount,
            2
        );
        $this->save();
    }
}
