<?php

namespace App\Policies;

use App\Models\SalesReturn;
use App\Models\User;

class SalesReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_sales_return');
    }

    public function view(User $user, SalesReturn $model): bool
    {
        return $user->hasPermissionTo('view_sales_return');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_sales_return');
    }

    public function update(User $user, SalesReturn $model): bool
    {
        return $user->hasPermissionTo('update_sales_return');
    }

    public function delete(User $user, SalesReturn $model): bool
    {
        return $user->hasPermissionTo('delete_sales_return');
    }

    public function restore(User $user, SalesReturn $model): bool
    {
        return $user->hasPermissionTo('delete_sales_return');
    }

    public function forceDelete(User $user, SalesReturn $model): bool
    {
        return $user->hasPermissionTo('delete_sales_return');
    }
}
