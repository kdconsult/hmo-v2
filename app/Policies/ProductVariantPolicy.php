<?php

namespace App\Policies;

use App\Models\ProductVariant;
use App\Models\User;

class ProductVariantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_product_variant');
    }

    public function view(User $user, ProductVariant $model): bool
    {
        return $user->hasPermissionTo('view_product_variant');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_product_variant');
    }

    public function update(User $user, ProductVariant $model): bool
    {
        return $user->hasPermissionTo('update_product_variant');
    }

    public function delete(User $user, ProductVariant $model): bool
    {
        return $user->hasPermissionTo('delete_product_variant');
    }

    public function restore(User $user, ProductVariant $model): bool
    {
        return $user->hasPermissionTo('delete_product_variant');
    }

    public function forceDelete(User $user, ProductVariant $model): bool
    {
        return $user->hasPermissionTo('delete_product_variant');
    }
}
