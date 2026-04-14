<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvancePaymentApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'advance_payment_id',
        'customer_invoice_id',
        'amount_applied',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_applied' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function advancePayment(): BelongsTo
    {
        return $this->belongsTo(AdvancePayment::class);
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
    }
}
