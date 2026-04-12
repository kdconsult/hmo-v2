<?php

namespace App\Policies;

use App\Models\GoodsReceivedNote;
use App\Models\User;

class GoodsReceivedNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_goods_received_note');
    }

    public function view(User $user, GoodsReceivedNote $model): bool
    {
        return $user->hasPermissionTo('view_goods_received_note');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_goods_received_note');
    }

    public function update(User $user, GoodsReceivedNote $model): bool
    {
        return $user->hasPermissionTo('update_goods_received_note');
    }

    public function delete(User $user, GoodsReceivedNote $model): bool
    {
        return $user->hasPermissionTo('delete_goods_received_note');
    }

    public function restore(User $user, GoodsReceivedNote $model): bool
    {
        return $user->hasPermissionTo('delete_goods_received_note');
    }

    public function forceDelete(User $user, GoodsReceivedNote $model): bool
    {
        return $user->hasPermissionTo('delete_goods_received_note');
    }
}
