<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_tag');
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('view_tag');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_tag');
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('update_tag');
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('delete_tag');
    }

    public function restore(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('delete_tag');
    }

    public function forceDelete(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('delete_tag');
    }
}
