<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_contract');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->hasPermissionTo('view_contract');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_contract');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->hasPermissionTo('update_contract');
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->hasPermissionTo('delete_contract');
    }

    public function restore(User $user, Contract $contract): bool
    {
        return $user->hasPermissionTo('delete_contract');
    }

    public function forceDelete(User $user, Contract $contract): bool
    {
        return $user->hasPermissionTo('delete_contract');
    }
}
