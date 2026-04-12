<?php

namespace App\Policies;

use App\Models\NumberSeries;
use App\Models\User;

class NumberSeriesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_number_series');
    }

    public function view(User $user, NumberSeries $numberSeries): bool
    {
        return $user->hasPermissionTo('view_number_series');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_number_series');
    }

    public function update(User $user, NumberSeries $numberSeries): bool
    {
        return $user->hasPermissionTo('update_number_series');
    }

    public function delete(User $user, NumberSeries $numberSeries): bool
    {
        return $user->hasPermissionTo('delete_number_series');
    }

    public function restore(User $user, NumberSeries $numberSeries): bool
    {
        return $user->hasPermissionTo('delete_number_series');
    }

    public function forceDelete(User $user, NumberSeries $numberSeries): bool
    {
        return $user->hasPermissionTo('delete_number_series');
    }
}
