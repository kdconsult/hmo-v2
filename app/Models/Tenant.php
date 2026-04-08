<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

class Tenant extends \Stancl\Tenancy\Database\Models\Tenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains, HasFactory;

    protected $casts = [
        'status' => TenantStatus::class,
        'deactivated_at' => 'datetime',
        'marked_for_deletion_at' => 'datetime',
        'scheduled_for_deletion_at' => 'datetime',
        'deletion_scheduled_for' => 'datetime',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'email',
            'phone',
            'address_line_1',
            'city',
            'postal_code',
            'country_code',
            'vat_number',
            'eik',
            'mol',
            'logo_path',
            'locale',
            'timezone',
            'default_currency_code',
            'subscription_plan',
            'subscription_ends_at',
            'status',
            'deactivated_at',
            'marked_for_deletion_at',
            'scheduled_for_deletion_at',
            'deletion_scheduled_for',
            'deactivation_reason',
            'deactivated_by',
        ];
    }

    // --- Relationships ---

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    // --- Scopes ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::Active->value);
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::Suspended->value);
    }

    public function scopeMarkedForDeletion(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::MarkedForDeletion->value);
    }

    public function scopeScheduledForDeletion(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::ScheduledForDeletion->value);
    }

    /** Tenants whose deletion_scheduled_for date is now or in the past. */
    public function scopeDueForDeletion(Builder $query): Builder
    {
        return $query->where('deletion_scheduled_for', '<=', now());
    }

    // --- Computed helpers ---

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended;
    }

    /** True when the tenant is either marked or scheduled for deletion. */
    public function isPendingDeletion(): bool
    {
        return in_array($this->status, [TenantStatus::MarkedForDeletion, TenantStatus::ScheduledForDeletion], true);
    }

    // --- Lifecycle transition methods ---

    /**
     * Suspend an active tenant (non-payment or administrative action).
     *
     * @param  string  $reason  'non_payment' | 'tenant_request' | 'other'
     */
    public function suspend(User $by, string $reason = 'non_payment'): void
    {
        $this->assertCanTransitionTo(TenantStatus::Suspended);

        $this->status = TenantStatus::Suspended;
        $this->deactivated_at = now();
        $this->deactivated_by = $by->id;
        $this->deactivation_reason = $reason;
        $this->save();
    }

    /**
     * Mark a suspended tenant for deletion (typically at ~3 months unpaid).
     */
    public function markForDeletion(): void
    {
        $this->assertCanTransitionTo(TenantStatus::MarkedForDeletion);

        $this->status = TenantStatus::MarkedForDeletion;
        $this->marked_for_deletion_at = now();
        $this->save();
    }

    /**
     * Schedule the tenant for automated deletion.
     *
     * @param  Carbon|null  $deleteOn  Defaults to 30 days from now.
     */
    public function scheduleForDeletion(?Carbon $deleteOn = null): void
    {
        $this->assertCanTransitionTo(TenantStatus::ScheduledForDeletion);

        $this->status = TenantStatus::ScheduledForDeletion;
        $this->scheduled_for_deletion_at = now();
        $this->deletion_scheduled_for = $deleteOn ?? now()->addDays(30);
        $this->save();
    }

    /**
     * Reactivate a suspended/marked/scheduled tenant.
     */
    public function reactivate(): void
    {
        $this->assertCanTransitionTo(TenantStatus::Active);

        $this->status = TenantStatus::Active;
        $this->deactivated_at = null;
        $this->deactivated_by = null;
        $this->deactivation_reason = null;
        $this->marked_for_deletion_at = null;
        $this->scheduled_for_deletion_at = null;
        $this->deletion_scheduled_for = null;
        $this->save();
    }

    // --- Internal helper ---

    private function assertCanTransitionTo(TenantStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new RuntimeException(
                "Cannot transition tenant [{$this->id}] from [{$this->status->value}] to [{$target->value}]."
            );
        }
    }
}
