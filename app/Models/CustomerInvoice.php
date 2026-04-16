<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\ReverseChargeOverrideReason;
use App\Enums\VatScenario;
use App\Enums\ViesResult;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CustomerInvoice extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'document_series_id',
        'sales_order_id',
        'partner_id',
        'status',
        'invoice_type',
        'is_reverse_charge',
        'vat_scenario',
        'vies_request_id',
        'vies_checked_at',
        'vies_result',
        'reverse_charge_manual_override',
        'reverse_charge_override_user_id',
        'reverse_charge_override_at',
        'reverse_charge_override_reason',
        'currency_code',
        'exchange_rate',
        'pricing_mode',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'amount_paid',
        'amount_due',
        'payment_method',
        'issued_at',
        'due_date',
        'notes',
        'internal_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'invoice_type' => InvoiceType::class,
            'pricing_mode' => PricingMode::class,
            'payment_method' => PaymentMethod::class,
            'is_reverse_charge' => 'boolean',
            'vat_scenario' => VatScenario::class,
            'vies_result' => ViesResult::class,
            'vies_checked_at' => 'datetime',
            'reverse_charge_manual_override' => 'boolean',
            'reverse_charge_override_at' => 'datetime',
            'reverse_charge_override_reason' => ReverseChargeOverrideReason::class,
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'issued_at' => 'date',
            'due_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'invoice_number', 'partner_id', 'total', 'vat_scenario', 'reverse_charge_manual_override'])
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

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'document_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerInvoiceItem::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CustomerCreditNote::class);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(CustomerDebitNote::class);
    }

    public function advancePaymentApplications(): HasMany
    {
        return $this->hasMany(AdvancePaymentApplication::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function overrideUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverse_charge_override_user_id');
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
        $this->subtotal = $this->items->sum(fn (CustomerInvoiceItem $item) => (float) $item->line_total);
        $this->tax_amount = $this->items->sum(fn (CustomerInvoiceItem $item) => (float) $item->vat_amount);
        $this->total = bcadd(
            bcsub((string) $this->subtotal, (string) $this->discount_amount, 2),
            (string) $this->tax_amount,
            2
        );
        $this->amount_due = bcsub((string) $this->total, (string) $this->amount_paid, 2);
        $this->save();
    }
}
