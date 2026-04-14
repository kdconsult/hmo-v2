<?php

namespace App\Models;

use App\Enums\AdvancePaymentStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AdvancePayment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'ap_number',
        'document_series_id',
        'partner_id',
        'sales_order_id',
        'customer_invoice_id',
        'status',
        'currency_code',
        'exchange_rate',
        'amount',
        'amount_applied',
        'payment_method',
        'received_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdvancePaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'amount_applied' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'received_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'ap_number', 'amount'])
            ->logOnlyDirty();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function advanceInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(AdvancePaymentApplication::class);
    }

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'document_series_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === AdvancePaymentStatus::Open;
    }

    public function remainingAmount(): string
    {
        return bcsub((string) $this->amount, (string) $this->amount_applied, 2);
    }

    public function isFullyApplied(): bool
    {
        return bccomp((string) $this->amount_applied, (string) $this->amount, 2) >= 0;
    }
}
