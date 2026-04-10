<?php

namespace App\Services;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use RuntimeException;

/**
 * Enforces lifecycle preconditions before a tenant database is permanently deleted.
 * Called by TenancyServiceProvider's DeletingTenant listener in non-testing environments.
 * Tested directly in TenantLifecycleTest without needing to bypass $tenant->delete().
 */
class TenantDeletionGuard
{
    public static function check(Tenant $tenant): void
    {
        if ($tenant->isLandlordTenant()) {
            throw new RuntimeException(
                "Cannot delete tenant [{$tenant->id}]: this is the landlord tenant and cannot be deleted."
            );
        }

        if ($tenant->status !== TenantStatus::ScheduledForDeletion) {
            throw new RuntimeException(
                "Cannot delete tenant [{$tenant->id}]: status must be ScheduledForDeletion, got [{$tenant->status->value}]."
            );
        }

        if ($tenant->deletion_scheduled_for === null) {
            throw new RuntimeException(
                "Cannot delete tenant [{$tenant->id}]: deletion_scheduled_for is not set."
            );
        }

        if ($tenant->deletion_scheduled_for->isFuture()) {
            throw new RuntimeException(
                "Cannot delete tenant [{$tenant->id}]: deletion_scheduled_for ({$tenant->deletion_scheduled_for}) is still in the future."
            );
        }
    }
}
