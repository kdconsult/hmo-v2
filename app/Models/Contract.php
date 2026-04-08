<?php

namespace App\Models;

use App\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Contract extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'contract_number',
        'document_series_id',
        'partner_id',
        'status',
        'type',
        'start_date',
        'end_date',
        'auto_renew',
        'monthly_fee',
        'currency_code',
        'included_hours',
        'included_materials_budget',
        'used_hours',
        'used_materials',
        'billing_day',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'auto_renew' => 'boolean',
            'monthly_fee' => 'decimal:2',
            'included_hours' => 'decimal:2',
            'included_materials_budget' => 'decimal:2',
            'used_hours' => 'decimal:2',
            'used_materials' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'contract_number', 'partner_id'])
            ->logOnlyDirty();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(DocumentSeries::class);
    }
}
