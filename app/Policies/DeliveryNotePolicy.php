<?php

namespace App\Policies;

use App\Models\DeliveryNote;
use App\Models\User;

class DeliveryNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_delivery_note');
    }

    public function view(User $user, DeliveryNote $model): bool
    {
        return $user->hasPermissionTo('view_delivery_note');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_delivery_note');
    }

    public function update(User $user, DeliveryNote $model): bool
    {
        return $user->hasPermissionTo('update_delivery_note');
    }

    public function delete(User $user, DeliveryNote $model): bool
    {
        return $user->hasPermissionTo('delete_delivery_note');
    }

    public function restore(User $user, DeliveryNote $model): bool
    {
        return $user->hasPermissionTo('delete_delivery_note');
    }

    public function forceDelete(User $user, DeliveryNote $model): bool
    {
        return $user->hasPermissionTo('delete_delivery_note');
    }
}
