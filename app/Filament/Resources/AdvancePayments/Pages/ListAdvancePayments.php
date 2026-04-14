<?php

namespace App\Filament\Resources\AdvancePayments\Pages;

use App\Filament\Resources\AdvancePayments\AdvancePaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdvancePayments extends ListRecords
{
    protected static string $resource = AdvancePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
