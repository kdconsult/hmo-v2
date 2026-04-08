<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;

class PlanLimitService
{
    /**
     * Check whether the tenant can add another user based on their plan limits.
     */
    public function canAddUser(Tenant $tenant): bool
    {
        $maxUsers = $tenant->plan?->max_users;

        if ($maxUsers === null) {
            return true; // unlimited
        }

        $count = $tenant->run(fn () => TenantUser::withoutTrashed()->count());

        return $count < $maxUsers;
    }

    /**
     * Check whether the tenant can create another document this month.
     * Pass the current month's document count for the calling context.
     */
    public function canCreateDocument(Tenant $tenant, int $currentMonthCount): bool
    {
        $maxDocuments = $tenant->plan?->max_documents;

        if ($maxDocuments === null) {
            return true; // unlimited
        }

        return $currentMonthCount < $maxDocuments;
    }
}
