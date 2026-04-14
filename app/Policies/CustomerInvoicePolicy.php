<?php

namespace App\Policies;

use App\Models\CustomerInvoice;
use App\Models\User;

class CustomerInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_customer_invoice');
    }

    public function view(User $user, CustomerInvoice $model): bool
    {
        return $user->hasPermissionTo('view_customer_invoice');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_customer_invoice');
    }

    public function update(User $user, CustomerInvoice $model): bool
    {
        return $user->hasPermissionTo('update_customer_invoice');
    }

    public function delete(User $user, CustomerInvoice $model): bool
    {
        return $user->hasPermissionTo('delete_customer_invoice');
    }

    public function restore(User $user, CustomerInvoice $model): bool
    {
        return $user->hasPermissionTo('delete_customer_invoice');
    }

    public function forceDelete(User $user, CustomerInvoice $model): bool
    {
        return $user->hasPermissionTo('delete_customer_invoice');
    }
}
