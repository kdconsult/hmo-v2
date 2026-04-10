<?php

namespace App\Policies;

use App\Models\User;

/**
 * Central DB policy for the landlord panel.
 * Not spatie-permission based — checks is_landlord flag directly.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_landlord;
    }

    public function view(User $user, User $model): bool
    {
        return $user->is_landlord;
    }

    public function create(User $user): bool
    {
        return $user->is_landlord;
    }

    public function update(User $user, User $model): bool
    {
        return $user->is_landlord;
    }

    /**
     * Landlord users can delete other users but not themselves.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->is_landlord && $user->id !== $model->id;
    }
}
