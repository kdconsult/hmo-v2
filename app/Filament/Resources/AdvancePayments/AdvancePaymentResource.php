<?php

namespace App\Filament\Resources\AdvancePayments;

use App\Enums\NavigationGroup;
use App\Filament\Resources\AdvancePayments\Pages\CreateAdvancePayment;
use App\Filament\Resources\AdvancePayments\Pages\EditAdvancePayment;
use App\Filament\Resources\AdvancePayments\Pages\ListAdvancePayments;
use App\Filament\Resources\AdvancePayments\Pages\ViewAdvancePayment;
use App\Filament\Resources\AdvancePayments\Schemas\AdvancePaymentForm;
use App\Filament\Resources\AdvancePayments\Tables\AdvancePaymentsTable;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\AdvancePayment;
use App\Models\AdvancePaymentApplication;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Advance Payment')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('ap_number')->label('AP Number'),
                        TextEntry::make('partner.name')->label('Customer'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('received_at')->date()->label('Received Date'),
                        TextEntry::make('payment_method')->label('Payment Method'),
                        TextEntry::make('amount')->money(fn (AdvancePayment $record): string => $record->currency_code),
                        TextEntry::make('amount_applied')
                            ->label('Applied Amount')
                            ->money(fn (AdvancePayment $record): string => $record->currency_code),
                        TextEntry::make('remaining_amount')
                            ->label('Remaining Amount')
                            ->state(fn (AdvancePayment $record): string => $record->remainingAmount())
                            ->money(fn (AdvancePayment $record): string => $record->currency_code),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (AdvancePayment $record): bool => filled($record->notes)),

                Section::make('Related Documents')
                    ->schema([
                        TextEntry::make('salesOrder.so_number')
                            ->label('Sales Order')
                            ->url(fn (AdvancePayment $record): string => SalesOrderResource::getUrl('view', ['record' => $record->sales_order_id]))
                            ->visible(fn (AdvancePayment $record): bool => $record->sales_order_id !== null),

                        TextEntry::make('advanceInvoice.invoice_number')
                            ->label('Advance Invoice')
                            ->url(fn (AdvancePayment $record): string => CustomerInvoiceResource::getUrl('view', ['record' => $record->customer_invoice_id]))
                            ->visible(fn (AdvancePayment $record): bool => $record->customer_invoice_id !== null),

                        RepeatableEntry::make('applications')
                            ->label('Applied to Invoices')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('customerInvoice.invoice_number')
                                    ->label('Invoice')
                                    ->url(fn (AdvancePaymentApplication $record): string => CustomerInvoiceResource::getUrl('view', ['record' => $record->customer_invoice_id])),
                                TextEntry::make('amount_applied')->money('EUR'),
                            ]),
                    ])
                    ->visible(fn (AdvancePayment $record): bool => $record->sales_order_id !== null
                        || $record->customer_invoice_id !== null
                        || $record->applications()->exists()
                    ),
            ]);
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
