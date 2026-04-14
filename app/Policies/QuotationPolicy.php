<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_quotation');
    }

    public function view(User $user, Quotation $model): bool
    {
        return $user->hasPermissionTo('view_quotation');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_quotation');
    }

    public function update(User $user, Quotation $model): bool
    {
        return $user->hasPermissionTo('update_quotation');
    }

    public function delete(User $user, Quotation $model): bool
    {
        return $user->hasPermissionTo('delete_quotation');
    }

    public function restore(User $user, Quotation $model): bool
    {
        return $user->hasPermissionTo('delete_quotation');
    }

    public function forceDelete(User $user, Quotation $model): bool
    {
        return $user->hasPermissionTo('delete_quotation');
    }
}
