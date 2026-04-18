<?php

namespace App\Models;

use App\Enums\PartnerType;
use App\Enums\PaymentMethod;
use App\Enums\VatStatus;
use App\Support\EuCountries;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Partner extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'type',
        'name',
        'company_name',
        'eik',
        'vat_number',
        'mol',
        'email',
        'phone',
        'secondary_phone',
        'website',
        'is_customer',
        'is_supplier',
        'default_currency_code',
        'default_payment_term_days',
        'default_payment_method',
        'default_vat_rate_id',
        'credit_limit',
        'discount_percent',
        'notes',
        'is_active',
        'country_code',
        'is_vat_registered',
        'vat_status',
        'vies_verified_at',
        'vies_last_checked_at',
        'vies_raw_address',
    ];

    protected function casts(): array
    {
        return [
            'type' => PartnerType::class,
            'default_payment_method' => PaymentMethod::class,
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'is_active' => 'boolean',
            'is_vat_registered' => 'boolean',
            'vat_status' => VatStatus::class,
            'vies_verified_at' => 'datetime',
            'vies_last_checked_at' => 'datetime',
            'credit_limit' => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active'])
            ->logOnlyDirty();
    }

    public function scopeActive($query): mixed
    {
        return $query->where('is_active', true);
    }

    public function scopeSuppliers($query): mixed
    {
        return $query->where('is_supplier', true);
    }

    public function scopeCustomers($query): mixed
    {
        return $query->where('is_customer', true);
    }

    public function hasValidEuVat(): bool
    {
        return $this->vat_status === VatStatus::Confirmed;
    }

    public function isEligibleForReverseCharge(): bool
    {
        return $this->vat_status === VatStatus::Confirmed
            && ! empty($this->country_code)
            && EuCountries::isEuCountry($this->country_code);
    }

    public function defaultVatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class, 'default_vat_rate_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(PartnerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PartnerContact::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartnerBankAccount::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
