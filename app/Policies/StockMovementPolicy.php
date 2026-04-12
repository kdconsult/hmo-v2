<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_stock_movement');
    }

    public function view(User $user, StockMovement $stockMovement): bool
    {
        return $user->hasPermissionTo('view_stock_movement');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_stock_movement');
    }

    public function update(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }

    public function restore(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }

    public function forceDelete(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }
}
