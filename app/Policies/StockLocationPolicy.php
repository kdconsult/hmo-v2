<?php

namespace App\Policies;

use App\Models\StockLocation;
use App\Models\User;

class StockLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_stock_location');
    }

    public function view(User $user, StockLocation $model): bool
    {
        return $user->hasPermissionTo('view_stock_location');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_stock_location');
    }

    public function update(User $user, StockLocation $model): bool
    {
        return $user->hasPermissionTo('update_stock_location');
    }

    public function delete(User $user, StockLocation $model): bool
    {
        return $user->hasPermissionTo('delete_stock_location');
    }

    public function restore(User $user, StockLocation $model): bool
    {
        return $user->hasPermissionTo('delete_stock_location');
    }

    public function forceDelete(User $user, StockLocation $model): bool
    {
        return $user->hasPermissionTo('delete_stock_location');
    }
}
