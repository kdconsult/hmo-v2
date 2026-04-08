<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VatRate;

class VatRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_vat_rate');
    }

    public function view(User $user, VatRate $vatRate): bool
    {
        return $user->hasPermissionTo('view_vat_rate');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_vat_rate');
    }

    public function update(User $user, VatRate $vatRate): bool
    {
        return $user->hasPermissionTo('update_vat_rate');
    }

    public function delete(User $user, VatRate $vatRate): bool
    {
        return $user->hasPermissionTo('delete_vat_rate');
    }

    public function restore(User $user, VatRate $vatRate): bool
    {
        return $user->hasPermissionTo('delete_vat_rate');
    }

    public function forceDelete(User $user, VatRate $vatRate): bool
    {
        return $user->hasPermissionTo('delete_vat_rate');
    }
}
