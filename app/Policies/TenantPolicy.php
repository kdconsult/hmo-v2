<?php

namespace App\Policies;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;

/**
 * Central DB policy for the landlord panel.
 * Not spatie-permission based — checks is_landlord flag directly.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_landlord;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->is_landlord;
    }

    public function create(User $user): bool
    {
        return $user->is_landlord;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->is_landlord;
    }

    /**
     * Direct tenant deletion is ALWAYS forbidden.
     * Deletion only happens via the automated hmo:delete-scheduled-tenants command.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return false;
    }

    public function suspend(User $user, Tenant $tenant): bool
    {
        return $user->is_landlord && $tenant->isActive() && ! $tenant->isLandlordTenant();
    }

    public function markForDeletion(User $user, Tenant $tenant): bool
    {
        return $user->is_landlord && $tenant->isSuspended() && ! $tenant->isLandlordTenant();
    }

    public function scheduleForDeletion(User $user, Tenant $tenant): bool
    {
        return $user->is_landlord
            && $tenant->status === TenantStatus::MarkedForDeletion
            && ! $tenant->isLandlordTenant();
    }

    public function reactivate(User $user, Tenant $tenant): bool
    {
        return $user->is_landlord && ! $tenant->isActive() && ! $tenant->isLandlordTenant();
    }
}
