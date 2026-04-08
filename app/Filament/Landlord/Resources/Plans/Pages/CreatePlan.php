<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Plans\Pages;

use App\Filament\Landlord\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;
}
