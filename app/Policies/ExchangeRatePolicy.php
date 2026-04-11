<?php

namespace App\Policies;

use App\Models\ExchangeRate;
use App\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_exchange_rate');
    }

    public function view(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->hasPermissionTo('view_exchange_rate');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_exchange_rate');
    }

    public function update(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->hasPermissionTo('update_exchange_rate');
    }

    public function delete(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->hasPermissionTo('delete_exchange_rate');
    }

    public function restore(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->hasPermissionTo('delete_exchange_rate');
    }

    public function forceDelete(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->hasPermissionTo('delete_exchange_rate');
    }
}
