<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class CustomerInvoiceItem extends Model
{
    use HasFactory;

    /**
     * F-031: economic inputs on a line item that become immutable once the
     * parent invoice leaves Draft. Derived values (discount_amount, vat_amount,
     * line_total, line_total_with_vat) may be recomputed post-confirmation
     * to stay consistent with the frozen economic inputs.
     *
     * Creation of new items on a non-Draft parent is not blocked here — legal
     * reviewing of flows like AdvancePaymentService that add deduction rows to
     * confirmed invoices is tracked as a backlog item.
     */
    /**
     * NOTE ON THE CREATING GAP: the guards below cover `updating` and `deleting`
     * but intentionally NOT `creating`. AdvancePaymentService::createAdvanceInvoice
     * and ::applyToFinalInvoice add item rows to already-Confirmed invoices
     * (advance invoice and advance deduction on final invoice). Adding a line
     * to an issued invoice is legally questionable (Art. 233 Directive adjacent)
     * and tracked as a backlog item for AdvancePaymentService redesign. Do not
     * "complete the pattern" by adding a creating guard without first fixing
     * that service, or the advance-payment flow breaks.
     */
    protected const FROZEN_FIELDS = [
        'customer_invoice_id',
        'sales_order_item_id',
        'product_variant_id',
        'description',
        'quantity',
        'unit_price',
        'discount_percent',
        'vat_rate_id',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            $parentStatus = $item->customerInvoice?->status;
            if (! ($parentStatus instanceof DocumentStatus) || $parentStatus === DocumentStatus::Draft) {
                return;
            }

            $disallowed = array_intersect(array_keys($item->getDirty()), self::FROZEN_FIELDS);
            if ($disallowed === []) {
                return;
            }

            throw new RuntimeException(
                'Cannot modify economic inputs of items on a non-Draft invoice '
                ."(#{$item->customerInvoice?->invoice_number}, status={$parentStatus->value}). "
                .'Disallowed field changes: '.implode(', ', $disallowed).'. '
                .'Issue a credit or debit note to correct.'
            );
        });

        static::deleting(function (self $item): void {
            $parentStatus = $item->customerInvoice?->status;
            if ($parentStatus instanceof DocumentStatus && $parentStatus !== DocumentStatus::Draft) {
                throw new RuntimeException(
                    'Cannot delete items of a non-Draft invoice '
                    ."(#{$item->customerInvoice?->invoice_number}, status={$parentStatus->value})."
                );
            }
        });
    }

    protected $fillable = [
        'customer_invoice_id',
        'sales_order_item_id',
        'product_variant_id',
        'description',
        'quantity',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'vat_rate_id',
        'vat_amount',
        'line_total',
        'line_total_with_vat',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'line_total_with_vat' => 'decimal:2',
        ];
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function creditNoteItems(): HasMany
    {
        return $this->hasMany(CustomerCreditNoteItem::class);
    }

    public function creditedQuantity(): string
    {
        return (string) $this->creditNoteItems()
            ->whereHas('customerCreditNote', fn ($q) => $q->where('status', '!=', DocumentStatus::Cancelled->value))
            ->sum('quantity');
    }

    public function remainingCreditableQuantity(): string
    {
        return bcsub((string) $this->quantity, $this->creditedQuantity(), 4);
    }
}
