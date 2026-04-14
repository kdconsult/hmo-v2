<?php

namespace App\Models;

use App\Enums\DebitNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CustomerDebitNote extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'debit_note_number',
        'document_series_id',
        'customer_invoice_id',
        'partner_id',
        'status',
        'currency_code',
        'exchange_rate',
        'pricing_mode',
        'reason',
        'reason_description',
        'subtotal',
        'tax_amount',
        'total',
        'issued_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'pricing_mode' => PricingMode::class,
            'reason' => DebitNoteReason::class,
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'debit_note_number', 'total'])
            ->logOnlyDirty();
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
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
        return $this->hasMany(CustomerDebitNoteItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items->sum(fn (CustomerDebitNoteItem $item) => (float) $item->line_total);
        $this->tax_amount = $this->items->sum(fn (CustomerDebitNoteItem $item) => (float) $item->vat_amount);
        $this->total = bcadd((string) $this->subtotal, (string) $this->tax_amount, 2);
        $this->save();
    }
}
