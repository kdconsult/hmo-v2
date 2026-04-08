<?php

namespace App\Policies;

use App\Models\Currency;
use App\Models\User;

class CurrencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_currency');
    }

    public function view(User $user, Currency $currency): bool
    {
        return $user->hasPermissionTo('view_currency');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_currency');
    }

    public function update(User $user, Currency $currency): bool
    {
        return $user->hasPermissionTo('update_currency');
    }

    public function delete(User $user, Currency $currency): bool
    {
        return $user->hasPermissionTo('delete_currency');
    }

    public function restore(User $user, Currency $currency): bool
    {
        return $user->hasPermissionTo('delete_currency');
    }

    public function forceDelete(User $user, Currency $currency): bool
    {
        return $user->hasPermissionTo('delete_currency');
    }
}
