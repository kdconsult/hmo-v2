<?php

namespace App\Policies;

use App\Models\SupplierCreditNote;
use App\Models\User;

class SupplierCreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_supplier_credit_note');
    }

    public function view(User $user, SupplierCreditNote $model): bool
    {
        return $user->hasPermissionTo('view_supplier_credit_note');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_supplier_credit_note');
    }

    public function update(User $user, SupplierCreditNote $model): bool
    {
        return $user->hasPermissionTo('update_supplier_credit_note');
    }

    public function delete(User $user, SupplierCreditNote $model): bool
    {
        return $user->hasPermissionTo('delete_supplier_credit_note');
    }

    public function restore(User $user, SupplierCreditNote $model): bool
    {
        return $user->hasPermissionTo('delete_supplier_credit_note');
    }

    public function forceDelete(User $user, SupplierCreditNote $model): bool
    {
        return $user->hasPermissionTo('delete_supplier_credit_note');
    }
}
