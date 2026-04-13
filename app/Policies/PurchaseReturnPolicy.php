<?php

namespace App\Policies;

use App\Models\PurchaseReturn;
use App\Models\User;

class PurchaseReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_purchase_return');
    }

    public function view(User $user, PurchaseReturn $model): bool
    {
        return $user->hasPermissionTo('view_purchase_return');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_purchase_return');
    }

    public function update(User $user, PurchaseReturn $model): bool
    {
        return $user->hasPermissionTo('update_purchase_return');
    }

    public function delete(User $user, PurchaseReturn $model): bool
    {
        return $user->hasPermissionTo('delete_purchase_return');
    }

    public function restore(User $user, PurchaseReturn $model): bool
    {
        return $user->hasPermissionTo('delete_purchase_return');
    }

    public function forceDelete(User $user, PurchaseReturn $model): bool
    {
        return $user->hasPermissionTo('delete_purchase_return');
    }
}
