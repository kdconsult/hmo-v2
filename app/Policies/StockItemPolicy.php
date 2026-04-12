<?php

namespace App\Policies;

use App\Models\StockItem;
use App\Models\User;

class StockItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_stock_item');
    }

    public function view(User $user, StockItem $stockItem): bool
    {
        return $user->hasPermissionTo('view_stock_item');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_stock_item');
    }

    public function update(User $user, StockItem $stockItem): bool
    {
        return $user->hasPermissionTo('update_stock_item');
    }

    public function delete(User $user, StockItem $stockItem): bool
    {
        return false;
    }

    public function restore(User $user, StockItem $stockItem): bool
    {
        return false;
    }

    public function forceDelete(User $user, StockItem $stockItem): bool
    {
        return false;
    }
}
