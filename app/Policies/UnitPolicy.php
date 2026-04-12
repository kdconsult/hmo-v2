<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_unit');
    }

    public function view(User $user, Unit $model): bool
    {
        return $user->hasPermissionTo('view_unit');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_unit');
    }

    public function update(User $user, Unit $model): bool
    {
        return $user->hasPermissionTo('update_unit');
    }

    public function delete(User $user, Unit $model): bool
    {
        return $user->hasPermissionTo('delete_unit');
    }

    public function restore(User $user, Unit $model): bool
    {
        return $user->hasPermissionTo('delete_unit');
    }

    public function forceDelete(User $user, Unit $model): bool
    {
        return $user->hasPermissionTo('delete_unit');
    }
}
