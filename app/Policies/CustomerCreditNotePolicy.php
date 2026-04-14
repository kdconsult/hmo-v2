<?php

namespace App\Policies;

use App\Models\CustomerCreditNote;
use App\Models\User;

class CustomerCreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_customer_credit_note');
    }

    public function view(User $user, CustomerCreditNote $model): bool
    {
        return $user->hasPermissionTo('view_customer_credit_note');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_customer_credit_note');
    }

    public function update(User $user, CustomerCreditNote $model): bool
    {
        return $user->hasPermissionTo('update_customer_credit_note');
    }

    public function delete(User $user, CustomerCreditNote $model): bool
    {
        return $user->hasPermissionTo('delete_customer_credit_note');
    }

    public function restore(User $user, CustomerCreditNote $model): bool
    {
        return $user->hasPermissionTo('delete_customer_credit_note');
    }

    public function forceDelete(User $user, CustomerCreditNote $model): bool
    {
        return $user->hasPermissionTo('delete_customer_credit_note');
    }
}
