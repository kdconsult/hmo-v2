<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Payments;

use App\Filament\Landlord\Resources\Payments\Pages\ListPayments;
use App\Filament\Landlord\Resources\Payments\Pages\ViewPayment;
use App\Filament\Landlord\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $recordTitleAttribute = 'bank_transfer_reference';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('tenant.name')->label('Tenant'),
            TextEntry::make('plan.name')->label('Plan')->badge(),
            TextEntry::make('amount')->money('EUR'),
            TextEntry::make('gateway')->badge(),
            TextEntry::make('status')->badge(),
            TextEntry::make('bank_transfer_reference')->label('Bank Reference'),
            TextEntry::make('stripe_payment_intent_id')->label('Stripe Payment Intent'),
            TextEntry::make('notes')->columnSpanFull(),
            TextEntry::make('paid_at')->dateTime(),
            TextEntry::make('period_start')->date(),
            TextEntry::make('period_end')->date(),
            TextEntry::make('recordedBy.name')->label('Recorded by'),
            TextEntry::make('created_at')->dateTime()->label('Created'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
