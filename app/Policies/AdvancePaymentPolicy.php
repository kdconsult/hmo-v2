<?php

namespace App\Policies;

use App\Models\AdvancePayment;
use App\Models\User;

class AdvancePaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_advance_payment');
    }

    public function view(User $user, AdvancePayment $model): bool
    {
        return $user->hasPermissionTo('view_advance_payment');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_advance_payment');
    }

    public function update(User $user, AdvancePayment $model): bool
    {
        return $user->hasPermissionTo('update_advance_payment');
    }

    public function delete(User $user, AdvancePayment $model): bool
    {
        return $user->hasPermissionTo('delete_advance_payment');
    }

    public function restore(User $user, AdvancePayment $model): bool
    {
        return $user->hasPermissionTo('delete_advance_payment');
    }

    public function forceDelete(User $user, AdvancePayment $model): bool
    {
        return $user->hasPermissionTo('delete_advance_payment');
    }
}
