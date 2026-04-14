<?php

namespace App\Policies;

use App\Models\SalesOrder;
use App\Models\User;

class SalesOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_sales_order');
    }

    public function view(User $user, SalesOrder $model): bool
    {
        return $user->hasPermissionTo('view_sales_order');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_sales_order');
    }

    public function update(User $user, SalesOrder $model): bool
    {
        return $user->hasPermissionTo('update_sales_order');
    }

    public function delete(User $user, SalesOrder $model): bool
    {
        return $user->hasPermissionTo('delete_sales_order');
    }

    public function restore(User $user, SalesOrder $model): bool
    {
        return $user->hasPermissionTo('delete_sales_order');
    }

    public function forceDelete(User $user, SalesOrder $model): bool
    {
        return $user->hasPermissionTo('delete_sales_order');
    }
}
