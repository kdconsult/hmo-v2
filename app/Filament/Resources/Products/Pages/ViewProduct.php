<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\ViewRecord\Concerns\Translatable;

class ViewProduct extends ViewRecord
{
    use Translatable;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
            EditAction::make(),
            ReplicateAction::make()
                ->excludeAttributes(['barcode'])
                ->mutateRecordDataUsing(function (array $data): array {
                    $data['code'] = ($data['code'] ?? '').'-COPY';

                    return $data;
                })
                ->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(50)
                        ->unique(table: 'products', ignoreRecord: true),
                ])
                ->after(function (Product $replica, Product $record): void {
                    $record->variants()
                        ->where('is_default', false)
                        ->get()
                        ->each(function ($variant) use ($replica): void {
                            $clone = $variant->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                            $clone->product_id = $replica->id;
                            $clone->sku = ($variant->sku ?? '').'-COPY';
                            $clone->save();
                        });
                })
                ->successRedirectUrl(fn (Product $replica): string => ProductResource::getUrl('edit', ['record' => $replica])),
        ];
    }
}
