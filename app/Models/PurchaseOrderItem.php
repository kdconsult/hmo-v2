<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_variant_id',
        'description',
        'quantity',
        'quantity_received',
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
            'quantity_received' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'line_total_with_vat' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function goodsReceivedNoteItems(): HasMany
    {
        return $this->hasMany(GoodsReceivedNoteItem::class);
    }

    public function supplierInvoiceItems(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    public function remainingQuantity(): string
    {
        return bcsub((string) $this->quantity, (string) $this->quantity_received, 4);
    }

    public function isFullyReceived(): bool
    {
        return bccomp((string) $this->quantity_received, (string) $this->quantity, 4) >= 0;
    }

    public function invoicedQuantity(): string
    {
        return (string) $this->supplierInvoiceItems()
            ->whereHas('supplierInvoice', fn ($q) => $q->where('status', '!=', DocumentStatus::Cancelled->value))
            ->sum('quantity');
    }

    public function remainingInvoiceableQuantity(): string
    {
        return bcsub((string) $this->quantity, $this->invoicedQuantity(), 4);
    }
}
