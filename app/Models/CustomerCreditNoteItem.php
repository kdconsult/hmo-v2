<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class CustomerCreditNoteItem extends Model
{
    use HasFactory;

    protected const FROZEN_FIELDS = [
        'customer_credit_note_id',
        'customer_invoice_item_id',
        'product_variant_id',
        'description',
        'quantity',
        'unit_price',
        'vat_rate_id',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            $parentStatus = $item->customerCreditNote?->status;
            if (! ($parentStatus instanceof DocumentStatus) || $parentStatus === DocumentStatus::Draft) {
                return;
            }

            $disallowed = array_intersect(array_keys($item->getDirty()), self::FROZEN_FIELDS);
            if ($disallowed === []) {
                return;
            }

            throw new RuntimeException(
                'Cannot modify economic inputs of items on a non-Draft credit note '
                ."(#{$item->customerCreditNote?->credit_note_number}, status={$parentStatus->value}). "
                .'Disallowed field changes: '.implode(', ', $disallowed).'. '
                .'Issue a new corrective document to correct.'
            );
        });

        static::deleting(function (self $item): void {
            $parentStatus = $item->customerCreditNote?->status;
            if ($parentStatus instanceof DocumentStatus && $parentStatus !== DocumentStatus::Draft) {
                throw new RuntimeException(
                    'Cannot delete items of a non-Draft credit note '
                    ."(#{$item->customerCreditNote?->credit_note_number}, status={$parentStatus->value})."
                );
            }
        });
    }

    protected $fillable = [
        'customer_credit_note_id',
        'customer_invoice_item_id',
        'product_variant_id',
        'description',
        'quantity',
        'unit_price',
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
            'vat_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'line_total_with_vat' => 'decimal:2',
        ];
    }

    public function customerCreditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditNote::class);
    }

    public function customerInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoiceItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }
}
