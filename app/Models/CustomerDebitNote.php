<?php

namespace App\Models;

use App\Enums\DebitNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Enums\VatScenario;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CustomerDebitNote extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Fields locked once the debit note leaves Draft status.
     * Mutable post-Draft: status, subtotal, tax_amount, total, triggering_event_date, vat_scenario_sub_code.
     * Legal basis: Art. 219 Directive 2006/112/EC; чл. 115 ЗДДС.
     */
    protected const FROZEN_FIELDS = [
        'debit_note_number',
        'issued_at',
        'partner_id',
        'customer_invoice_id',
        'document_series_id',
        'currency_code',
        'exchange_rate',
        'pricing_mode',
        'vat_scenario',
        'is_reverse_charge',
        'reason',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $note): void {
            $original = $note->getOriginal('status');
            $originalValue = $original instanceof DocumentStatus ? $original->value : $original;

            if ($originalValue === null || $originalValue === DocumentStatus::Draft->value) {
                return;
            }

            $disallowed = array_intersect(array_keys($note->getDirty()), self::FROZEN_FIELDS);
            if ($disallowed === []) {
                return;
            }

            throw new RuntimeException(
                'Confirmed debit notes are immutable (Art. 219 Directive 2006/112/EC; chl. 115 ZDDS). '
                ."Debit note #{$note->debit_note_number} (status={$originalValue}). "
                .'Disallowed field changes: '.implode(', ', $disallowed).'. '
                .'Issue a new corrective document to correct.'
            );
        });

        static::deleting(function (self $note): void {
            $original = $note->getOriginal('status') ?? $note->status;
            $originalValue = $original instanceof DocumentStatus ? $original->value : $original;

            if ($originalValue !== null && $originalValue !== DocumentStatus::Draft->value) {
                throw new RuntimeException(
                    "Cannot delete a non-Draft debit note (#{$note->debit_note_number}, status={$originalValue}). "
                    .'Cancel it via the Cancel action instead.'
                );
            }
        });
    }

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
        'vat_scenario',
        'vat_scenario_sub_code',
        'is_reverse_charge',
        'triggering_event_date',
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
            'vat_scenario' => VatScenario::class,
            'is_reverse_charge' => 'boolean',
            'triggering_event_date' => 'date',
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
