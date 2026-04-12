<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Support\TranslatableLocales;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LaraZeus\SpatieTranslatable\Resources\RelationManagers\Concerns\Translatable;
use Livewire\Attributes\Reactive;

class ProductVariantsRelationManager extends RelationManager
{
    use Translatable;

    protected static string $relationship = 'variants';

    public function getTranslatableLocales(): array
    {
        return TranslatableLocales::forTenant();
    }

    protected static ?string $title = 'Variants';

    #[Reactive]
    public ?string $activeLocale = null;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('sku')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                TextInput::make('purchase_price')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->nullable(),
                TextInput::make('sale_price')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->nullable(),
                TextInput::make('barcode')
                    ->maxLength(128)
                    ->nullable(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('is_default', false))
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('sku')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sale_price')
                    ->numeric(2)
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
