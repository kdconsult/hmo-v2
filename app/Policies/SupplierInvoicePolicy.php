<?php

namespace App\Policies;

use App\Models\SupplierInvoice;
use App\Models\User;

class SupplierInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_supplier_invoice');
    }

    public function view(User $user, SupplierInvoice $model): bool
    {
        return $user->hasPermissionTo('view_supplier_invoice');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_supplier_invoice');
    }

    public function update(User $user, SupplierInvoice $model): bool
    {
        return $user->hasPermissionTo('update_supplier_invoice');
    }

    public function delete(User $user, SupplierInvoice $model): bool
    {
        return $user->hasPermissionTo('delete_supplier_invoice');
    }

    public function restore(User $user, SupplierInvoice $model): bool
    {
        return $user->hasPermissionTo('delete_supplier_invoice');
    }

    public function forceDelete(User $user, SupplierInvoice $model): bool
    {
        return $user->hasPermissionTo('delete_supplier_invoice');
    }
}
