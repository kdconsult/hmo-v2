<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_order_id',
        'quotation_item_id',
        'product_variant_id',
        'description',
        'quantity',
        'qty_delivered',
        'qty_invoiced',
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
            'qty_delivered' => 'decimal:4',
            'qty_invoiced' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'line_total_with_vat' => 'decimal:2',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function deliveryNoteItems(): HasMany
    {
        return $this->hasMany(DeliveryNoteItem::class);
    }

    public function customerInvoiceItems(): HasMany
    {
        return $this->hasMany(CustomerInvoiceItem::class);
    }

    public function remainingDeliverableQuantity(): string
    {
        return bcsub((string) $this->quantity, (string) $this->qty_delivered, 4);
    }

    public function remainingInvoiceableQuantity(): string
    {
        return bcsub((string) $this->quantity, (string) $this->qty_invoiced, 4);
    }

    public function isFullyDelivered(): bool
    {
        return bccomp((string) $this->qty_delivered, (string) $this->quantity, 4) >= 0;
    }

    public function isFullyInvoiced(): bool
    {
        return bccomp((string) $this->qty_invoiced, (string) $this->quantity, 4) >= 0;
    }
}
