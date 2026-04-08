<?php

namespace App\Policies;

use App\Models\DocumentSeries;
use App\Models\User;

class DocumentSeriesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view_any_document_series');
    }

    public function view(User $user, DocumentSeries $documentSeries): bool
    {
        return $user->hasPermissionTo('view_document_series');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create_document_series');
    }

    public function update(User $user, DocumentSeries $documentSeries): bool
    {
        return $user->hasPermissionTo('update_document_series');
    }

    public function delete(User $user, DocumentSeries $documentSeries): bool
    {
        return $user->hasPermissionTo('delete_document_series');
    }

    public function restore(User $user, DocumentSeries $documentSeries): bool
    {
        return $user->hasPermissionTo('delete_document_series');
    }

    public function forceDelete(User $user, DocumentSeries $documentSeries): bool
    {
        return $user->hasPermissionTo('delete_document_series');
    }
}
