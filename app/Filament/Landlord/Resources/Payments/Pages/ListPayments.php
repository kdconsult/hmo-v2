<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Payments\Pages;

use App\Filament\Landlord\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;
}
