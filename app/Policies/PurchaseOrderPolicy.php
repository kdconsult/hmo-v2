<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_purchase_order');
    }

    public function view(User $user, PurchaseOrder $model): bool
    {
        return $user->hasPermissionTo('view_purchase_order');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_purchase_order');
    }

    public function update(User $user, PurchaseOrder $model): bool
    {
        return $user->hasPermissionTo('update_purchase_order');
    }

    public function delete(User $user, PurchaseOrder $model): bool
    {
        return $user->hasPermissionTo('delete_purchase_order');
    }

    public function restore(User $user, PurchaseOrder $model): bool
    {
        return $user->hasPermissionTo('delete_purchase_order');
    }

    public function forceDelete(User $user, PurchaseOrder $model): bool
    {
        return $user->hasPermissionTo('delete_purchase_order');
    }
}
