<?php

namespace App\Filament\Landlord\Resources\Tenants\Pages;

use App\Filament\Landlord\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
}
