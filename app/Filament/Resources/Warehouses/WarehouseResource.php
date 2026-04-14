<?php

namespace App\Filament\Resources\Warehouses;

use App\Enums\NavigationGroup;
use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Resources\Warehouses\Pages\ViewWarehouse;
use App\Filament\Resources\Warehouses\RelationManagers\StockLocationsRelationManager;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Warehouse;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('is_default')
                            ->default(false),
                    ]),

                Section::make('Address')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address.street')
                            ->label('Street')
                            ->maxLength(255),
                        TextInput::make('address.city')
                            ->label('City')
                            ->maxLength(100),
                        TextInput::make('address.postal_code')
                            ->label('Postal Code')
                            ->maxLength(20),
                        TextInput::make('address.country')
                            ->label('Country')
                            ->default('BG')
                            ->maxLength(10),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('view_stock')
                    ->label('Stock')
                    ->icon(Heroicon::OutlinedCube)
                    ->url(fn (Warehouse $record): string => StockItemResource::getUrl('index').'?'.Arr::query([
                        'tableFilters' => ['warehouse' => ['value' => $record->id]],
                    ])),
                Action::make('view_movements')
                    ->label('Movements')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (Warehouse $record): string => StockMovementResource::getUrl('index').'?'.Arr::query([
                        'tableFilters' => ['warehouse' => ['value' => $record->id]],
                    ])),
                RestoreAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            StockLocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'view' => ViewWarehouse::route('/{record}'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
