<?php

namespace App\Policies;

use App\Models\Partner;
use App\Models\User;

class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_partner');
    }

    public function view(User $user, Partner $partner): bool
    {
        return $user->hasPermissionTo('view_partner');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_partner');
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->hasPermissionTo('update_partner');
    }

    public function delete(User $user, Partner $partner): bool
    {
        return $user->hasPermissionTo('delete_partner');
    }

    public function restore(User $user, Partner $partner): bool
    {
        return $user->hasPermissionTo('delete_partner');
    }

    public function forceDelete(User $user, Partner $partner): bool
    {
        return $user->hasPermissionTo('delete_partner');
    }
}
