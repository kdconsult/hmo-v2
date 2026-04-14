<?php

namespace App\Filament\Resources\Categories;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Pages\ViewCategory;
use App\Models\Category;
use App\Models\Unit;
use App\Models\VatRate;
use App\Support\TranslatableLocales;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

class CategoryResource extends Resource
{
    use Translatable;

    protected static ?string $model = Category::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getTranslatableLocales(): array
    {
        return TranslatableLocales::forTenant();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('parent_id')
                    ->label('Parent Category')
                    ->options(fn (?Category $record) => Category::query()
                        ->whereNull('parent_id')
                        ->when($record?->id, fn ($q) => $q->where('id', '!=', $record->id))
                        ->orderBy('id')
                        ->get()
                        ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->default(true),
                Section::make('Product Defaults')
                    ->description('Default values inherited by products created in this category.')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Select::make('default_vat_rate_id')
                            ->label('Default VAT Rate')
                            ->options(fn () => VatRate::active()->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Products will inherit this VAT rate if not specified.'),
                        Select::make('default_unit_id')
                            ->label('Default Unit')
                            ->options(fn () => Unit::active()->get()->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Products will inherit this unit if not specified.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('parent.name')
                    ->placeholder('Root'),
                TextColumn::make('defaultVatRate.name')
                    ->label('Default VAT')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('defaultUnit.name')
                    ->label('Default Unit')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'view' => ViewCategory::route('/{record}'),
            'edit' => EditCategory::route('/{record}/edit'),
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
