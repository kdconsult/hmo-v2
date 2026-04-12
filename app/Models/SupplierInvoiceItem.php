<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'purchase_order_item_id',
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

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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
        return $this->hasMany(SupplierCreditNoteItem::class);
    }

    public function creditedQuantity(): string
    {
        return (string) $this->creditNoteItems()
            ->whereHas('supplierCreditNote', fn ($q) => $q->where('status', '!=', DocumentStatus::Cancelled->value))
            ->sum('quantity');
    }

    public function remainingCreditableQuantity(): string
    {
        return bcsub((string) $this->quantity, $this->creditedQuantity(), 4);
    }
}
