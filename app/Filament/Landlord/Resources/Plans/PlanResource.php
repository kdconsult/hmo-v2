<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Plans;

use App\Filament\Landlord\Resources\Plans\Pages\CreatePlan;
use App\Filament\Landlord\Resources\Plans\Pages\EditPlan;
use App\Filament\Landlord\Resources\Plans\Pages\ListPlans;
use App\Filament\Landlord\Resources\Plans\Pages\ViewPlan;
use App\Filament\Landlord\Resources\Plans\Schemas\PlanForm;
use App\Filament\Landlord\Resources\Plans\Tables\PlansTable;
use App\Models\Plan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'view' => ViewPlan::route('/{record}'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
