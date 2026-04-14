<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCreditNoteItem extends Model
{
    use HasFactory;

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
