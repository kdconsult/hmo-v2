<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_warehouse');
    }

    public function view(User $user, Warehouse $model): bool
    {
        return $user->hasPermissionTo('view_warehouse');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_warehouse');
    }

    public function update(User $user, Warehouse $model): bool
    {
        return $user->hasPermissionTo('update_warehouse');
    }

    public function delete(User $user, Warehouse $model): bool
    {
        return $user->hasPermissionTo('delete_warehouse');
    }

    public function restore(User $user, Warehouse $model): bool
    {
        return $user->hasPermissionTo('delete_warehouse');
    }

    public function forceDelete(User $user, Warehouse $model): bool
    {
        return $user->hasPermissionTo('delete_warehouse');
    }
}
