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
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CustomerInvoice extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * F-031: economic / face-content fields that become immutable once the invoice
     * leaves Draft status. Mutations to these on a confirmed invoice throw
     * RuntimeException; corrections must go through credit / debit notes.
     *
     * Derived totals (subtotal / discount_amount / tax_amount / total) are NOT
     * frozen — they must remain recomputable so that they stay in sync with
     * item rows when a service layer touches the invoice post-confirmation
     * (e.g. advance-payment deduction lines). Item-level economic inputs are
     * locked in CustomerInvoiceItem::FROZEN_FIELDS; as long as those can't
     * change, totals recompute to the same value.
     *
     * Mutable post-Draft: status, amount_paid, amount_due, payment_method,
     * due_date, internal_notes, subtotal, discount_amount, tax_amount, total,
     * deleted_at.
     *
     * Legal basis: Art. 233 Directive 2006/112/EC; чл. 114, ал. 6 ЗДДС.
     */
    protected const FROZEN_FIELDS = [
        'invoice_number',
        'issued_at',
        'partner_id',
        'created_by',
        'document_series_id',
        'sales_order_id',
        'invoice_type',
        'currency_code',
        'exchange_rate',
        'pricing_mode',
        'vat_scenario',
        'is_reverse_charge',
        'vies_request_id',
        'vies_checked_at',
        'vies_result',
        'reverse_charge_manual_override',
        'reverse_charge_override_user_id',
        'reverse_charge_override_at',
        'reverse_charge_override_reason',
        'reverse_charge_override_acknowledgement',
        'notes',
    ];

    protected $fillable = [
        'invoice_number',
        'document_series_id',
        'sales_order_id',
        'partner_id',
        'status',
        'invoice_type',
        'is_reverse_charge',
        'vat_scenario',
        'vat_scenario_sub_code',
        'vies_request_id',
        'vies_checked_at',
        'vies_result',
        'reverse_charge_manual_override',
        'reverse_charge_override_user_id',
        'reverse_charge_override_at',
        'reverse_charge_override_reason',
        'reverse_charge_override_acknowledgement',
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
        'supplied_at',
        'due_date',
        'notes',
        'internal_notes',
        'created_by',
        'exchange_rate_source',
        'document_hash',
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
            'reverse_charge_override_acknowledgement' => 'boolean',
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
            'supplied_at' => 'date',
            'due_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $original = $invoice->getOriginal('status');
            $originalValue = $original instanceof DocumentStatus ? $original->value : $original;

            // Draft is freely mutable; confirmation (Draft -> Confirmed) also passes.
            if ($originalValue === null || $originalValue === DocumentStatus::Draft->value) {
                return;
            }

            $disallowed = array_intersect(array_keys($invoice->getDirty()), self::FROZEN_FIELDS);
            if ($disallowed === []) {
                return;
            }

            throw new RuntimeException(
                'Confirmed invoices are immutable (Art. 233 Directive 2006/112/EC; chl. 114, al. 6 ZDDS). '
                ."Invoice #{$invoice->invoice_number} (status={$originalValue}). "
                .'Disallowed field changes: '.implode(', ', $disallowed).'. '
                .'Issue a credit or debit note to correct.'
            );
        });

        static::deleting(function (self $invoice): void {
            $original = $invoice->getOriginal('status') ?? $invoice->status;
            $originalValue = $original instanceof DocumentStatus ? $original->value : $original;

            if ($originalValue !== null && $originalValue !== DocumentStatus::Draft->value) {
                throw new RuntimeException(
                    "Cannot delete a non-Draft invoice (#{$invoice->invoice_number}, status={$originalValue}). "
                    .'Cancel it via the Cancel action instead.'
                );
            }
        });
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
