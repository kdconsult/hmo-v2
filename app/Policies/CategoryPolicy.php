<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_category');
    }

    public function view(User $user, Category $model): bool
    {
        return $user->hasPermissionTo('view_category');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_category');
    }

    public function update(User $user, Category $model): bool
    {
        return $user->hasPermissionTo('update_category');
    }

    public function delete(User $user, Category $model): bool
    {
        return $user->hasPermissionTo('delete_category');
    }

    public function restore(User $user, Category $model): bool
    {
        return $user->hasPermissionTo('delete_category');
    }

    public function forceDelete(User $user, Category $model): bool
    {
        return $user->hasPermissionTo('delete_category');
    }
}
