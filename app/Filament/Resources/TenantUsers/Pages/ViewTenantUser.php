<?php

namespace App\Filament\Resources\TenantUsers\Pages;

use App\Filament\Resources\TenantUsers\TenantUserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTenantUser extends ViewRecord
{
    protected static string $resource = TenantUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
