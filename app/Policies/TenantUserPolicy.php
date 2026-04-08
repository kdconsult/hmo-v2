<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Models\User;

class TenantUserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_tenant_user');
    }

    public function view(User $user, TenantUser $tenantUser): bool
    {
        return $user->hasPermissionTo('view_tenant_user');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_tenant_user');
    }

    public function update(User $user, TenantUser $tenantUser): bool
    {
        return $user->hasPermissionTo('update_tenant_user');
    }

    public function delete(User $user, TenantUser $tenantUser): bool
    {
        return $user->hasPermissionTo('delete_tenant_user');
    }

    public function restore(User $user, TenantUser $tenantUser): bool
    {
        return $user->hasPermissionTo('delete_tenant_user');
    }

    public function forceDelete(User $user, TenantUser $tenantUser): bool
    {
        return $user->hasPermissionTo('delete_tenant_user');
    }
}
