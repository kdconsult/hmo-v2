<?php

namespace App\Policies;

use App\Models\CustomerDebitNote;
use App\Models\User;

class CustomerDebitNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_customer_debit_note');
    }

    public function view(User $user, CustomerDebitNote $model): bool
    {
        return $user->hasPermissionTo('view_customer_debit_note');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_customer_debit_note');
    }

    public function update(User $user, CustomerDebitNote $model): bool
    {
        return $user->hasPermissionTo('update_customer_debit_note');
    }

    public function delete(User $user, CustomerDebitNote $model): bool
    {
        return $user->hasPermissionTo('delete_customer_debit_note');
    }

    public function restore(User $user, CustomerDebitNote $model): bool
    {
        return $user->hasPermissionTo('delete_customer_debit_note');
    }

    public function forceDelete(User $user, CustomerDebitNote $model): bool
    {
        return $user->hasPermissionTo('delete_customer_debit_note');
    }
}
