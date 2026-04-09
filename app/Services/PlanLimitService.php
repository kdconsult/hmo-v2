<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;

/**
 * @phpstan-type UsageSummary array{users: array{current: int, max: int|null, unlimited: bool}, documents: array{current: int, max: int|null, unlimited: bool}}
 */
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
     * Return current usage vs plan limits for users and documents.
     *
     * @return array{users: array{current: int, max: int|null, unlimited: bool}, documents: array{current: int, max: int|null, unlimited: bool}}
     */
    public function getUsageSummary(Tenant $tenant): array
    {
        $plan = $tenant->plan;
        $currentUsers = $tenant->run(fn () => TenantUser::withoutTrashed()->count());

        return [
            'users' => [
                'current' => $currentUsers,
                'max' => $plan?->max_users,
                'unlimited' => $plan?->max_users === null,
            ],
            'documents' => [
                'current' => 0, // document model implemented in a later phase
                'max' => $plan?->max_documents,
                'unlimited' => $plan?->max_documents === null,
            ],
        ];
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
