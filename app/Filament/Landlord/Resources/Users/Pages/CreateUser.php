<?php

namespace App\Filament\Landlord\Resources\Users\Pages;

use App\Filament\Landlord\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
