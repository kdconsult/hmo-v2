<?php

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Warehouse;
use App\Services\StockService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StockAdjustmentPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.stock-adjustment-page';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Warehouse;

    protected static ?string $navigationLabel = 'Stock Adjustment';

    public array $data = [];

    public ?int $product_id = null;

    public ?int $product_variant_id = null;

    public ?int $warehouse_id = null;

    public ?int $stock_location_id = null;

    public ?string $quantity = null;

    public ?string $reason = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('create_stock_movement') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::active()->get()->pluck('name', 'id')->filter(fn ($name) => filled($name)))
                    ->searchable()
                    ->live()
                    ->required()
                    ->afterStateUpdated(fn ($set) => $set('product_variant_id', null)),

                Select::make('product_variant_id')
                    ->label('Variant')
                    ->options(fn (Get $get) => ProductVariant::query()
                        ->where('product_id', $get('product_id'))
                        ->where('is_default', false)
                        ->where('is_active', true)
                        ->get()
                        ->pluck('name', 'id')
                        ->filter(fn ($name) => filled($name))
                    )
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get): bool => filled($get('product_id'))),

                Select::make('warehouse_id')
                    ->label('Warehouse')
                    ->options(Warehouse::query()->active()->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($set) => $set('stock_location_id', null)),

                Select::make('stock_location_id')
                    ->label('Location (optional)')
                    ->options(fn (Get $get) => StockLocation::query()
                        ->where('warehouse_id', $get('warehouse_id'))
                        ->where('is_active', true)
                        ->pluck('name', 'id')
                    )
                    ->nullable()
                    ->searchable()
                    ->visible(fn (Get $get): bool => filled($get('warehouse_id'))),

                TextInput::make('quantity')
                    ->label('Quantity (+ to add, - to remove)')
                    ->required()
                    ->numeric(),

                Textarea::make('reason')
                    ->required()
                    ->maxLength(500),
            ]);
    }

    public function adjust(StockService $stockService): void
    {
        $data = $this->form->getState();

        $product = Product::findOrFail($data['product_id']);

        if (filled($data['product_variant_id'])) {
            $variant = ProductVariant::findOrFail($data['product_variant_id']);
        } else {
            $variant = $product->defaultVariant;
        }

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        $location = filled($data['stock_location_id'] ?? null)
            ? StockLocation::find($data['stock_location_id'])
            : null;

        $stockService->adjust($variant, $warehouse, $data['quantity'], $data['reason'], $location);

        Notification::make()
            ->title('Stock adjusted successfully')
            ->success()
            ->send();

        $this->form->fill();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')
                ->label('Apply Adjustment')
                ->action('adjust')
                ->color('primary'),
        ];
    }
}
