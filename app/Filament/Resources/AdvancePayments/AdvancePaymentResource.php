<?php

namespace App\Filament\Resources\AdvancePayments;

use App\Enums\NavigationGroup;
use App\Filament\Resources\AdvancePayments\Pages\CreateAdvancePayment;
use App\Filament\Resources\AdvancePayments\Pages\EditAdvancePayment;
use App\Filament\Resources\AdvancePayments\Pages\ListAdvancePayments;
use App\Filament\Resources\AdvancePayments\Pages\ViewAdvancePayment;
use App\Filament\Resources\AdvancePayments\Schemas\AdvancePaymentForm;
use App\Filament\Resources\AdvancePayments\Tables\AdvancePaymentsTable;
use App\Models\AdvancePayment;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AdvancePaymentResource extends Resource
{
    protected static ?string $model = AdvancePayment::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Advance Payments';

    protected static ?string $recordTitleAttribute = 'ap_number';

    public static function form(Schema $schema): Schema
    {
        return AdvancePaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdvancePaymentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdvancePayments::route('/'),
            'create' => CreateAdvancePayment::route('/create'),
            'view' => ViewAdvancePayment::route('/{record}'),
            'edit' => EditAdvancePayment::route('/{record}/edit'),
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
