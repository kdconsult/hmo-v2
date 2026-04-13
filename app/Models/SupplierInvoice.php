<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SupplierInvoice extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'supplier_invoice_number',
        'internal_number',
        'document_series_id',
        'purchase_order_id',
        'partner_id',
        'status',
        'currency_code',
        'exchange_rate',
        'pricing_mode',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'amount_paid',
        'amount_due',
        'issued_at',
        'received_at',
        'due_date',
        'payment_method',
        'notes',
        'internal_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'pricing_mode' => PricingMode::class,
            'payment_method' => PaymentMethod::class,
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'issued_at' => 'date',
            'received_at' => 'date',
            'due_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'internal_number', 'partner_id', 'total'])
            ->logOnlyDirty();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'document_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(SupplierCreditNote::class);
    }

    public function goodsReceivedNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivedNote::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isOverdue(): bool
    {
        return $this->due_date < now()->toDateString()
            && bccomp((string) $this->amount_due, '0', 2) > 0;
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items->sum(fn (SupplierInvoiceItem $item) => (float) $item->line_total);
        $this->tax_amount = $this->items->sum(fn (SupplierInvoiceItem $item) => (float) $item->vat_amount);
        $this->total = bcadd(
            bcsub((string) $this->subtotal, (string) $this->discount_amount, 2),
            (string) $this->tax_amount,
            2
        );
        $this->amount_due = bcsub((string) $this->total, (string) $this->amount_paid, 2);
        $this->save();
    }
}
