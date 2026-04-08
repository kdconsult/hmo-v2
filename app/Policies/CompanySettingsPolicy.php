<?php

namespace App\Policies;

use App\Models\User;

class CompanySettingsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_company_settings');
    }

    public function view(User $user): bool
    {
        return $user->hasPermissionTo('view_company_settings');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_company_settings');
    }

    public function update(User $user): bool
    {
        return $user->hasPermissionTo('update_company_settings');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermissionTo('delete_company_settings');
    }
}
