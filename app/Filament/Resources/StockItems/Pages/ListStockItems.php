<?php

namespace App\Filament\Resources\StockItems\Pages;

use App\Filament\Resources\StockItems\StockItemResource;
use Filament\Resources\Pages\ListRecords;

class ListStockItems extends ListRecords
{
    protected static string $resource = StockItemResource::class;
}
