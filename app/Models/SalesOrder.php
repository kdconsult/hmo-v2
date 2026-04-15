<?php

namespace App\Models;

use App\Enums\AdvancePaymentStatus;
use App\Enums\PricingMode;
use App\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SalesOrder extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'so_number',
        'document_series_id',
        'partner_id',
        'quotation_id',
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
        'issued_at',
        'notes',
        'internal_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'pricing_mode' => PricingMode::class,
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'expected_delivery_date' => 'date',
            'issued_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'so_number', 'partner_id', 'total'])
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'document_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }

    public function customerInvoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === SalesOrderStatus::Draft;
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items->sum(fn (SalesOrderItem $item) => (float) $item->line_total);
        $this->tax_amount = $this->items->sum(fn (SalesOrderItem $item) => (float) $item->vat_amount);
        $this->total = bcadd(
            bcsub((string) $this->subtotal, (string) $this->discount_amount, 2),
            (string) $this->tax_amount,
            2
        );
        $this->save();
    }

    public function isFullyDelivered(): bool
    {
        return $this->items->every(fn (SalesOrderItem $item) => $item->isFullyDelivered());
    }

    public function isFullyInvoiced(): bool
    {
        return $this->items->every(fn (SalesOrderItem $item) => $item->isFullyInvoiced());
    }

    /**
     * Returns the percentage of ordered quantity that has been delivered (0–100).
     */
    public function fulfillmentPercentage(): int
    {
        $this->loadMissing('items');

        $ordered = $this->items->sum(fn (SalesOrderItem $item) => (float) $item->quantity);

        if ($ordered <= 0) {
            return 0;
        }

        $delivered = $this->items->sum(fn (SalesOrderItem $item) => (float) $item->qty_delivered);

        return (int) min(100, round(($delivered / $ordered) * 100));
    }

    public function advancePayments(): HasMany
    {
        return $this->hasMany(AdvancePayment::class);
    }

    /**
     * Returns the remaining balance available for additional advance payments.
     * = SO total minus all non-refunded advance payment amounts.
     */
    public function remainingBalance(): string
    {
        $advancesTotal = (string) $this->advancePayments()
            ->whereNotIn('status', [AdvancePaymentStatus::Refunded->value])
            ->sum('amount');

        return bcsub((string) $this->total, $advancesTotal, 2);
    }
}
