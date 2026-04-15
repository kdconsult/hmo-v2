<?php

namespace App\Filament\Resources\DeliveryNotes\Schemas;

use App\Enums\SalesOrderStatus;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class DeliveryNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Delivery Note')
                    ->columns(2)
                    ->schema([
                        TextInput::make('dn_number')
                            ->label('DN Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('sales_order_id')
                            ->label('Sales Order (optional)')
                            ->options(
                                SalesOrder::whereIn('status', [
                                    SalesOrderStatus::Confirmed->value,
                                    SalesOrderStatus::PartiallyDelivered->value,
                                ])
                                    ->with('partner')
                                    ->get()
                                    ->mapWithKeys(fn (SalesOrder $so) => [
                                        $so->id => "{$so->so_number} — {$so->partner->name}",
                                    ])
                            )
                            ->searchable()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $so = SalesOrder::with('partner')->find($state);
                                    if ($so) {
                                        $set('partner_id', $so->partner_id);
                                        $set('warehouse_id', $so->warehouse_id);
                                    }
                                }
                            }),
                        Select::make('partner_id')
                            ->label('Customer')
                            ->options(
                                Partner::customers()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get): bool => ! empty($get('sales_order_id')))
                            ->dehydrated(),
                        Select::make('warehouse_id')
                            ->label('Dispatch Warehouse')
                            ->options(Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get): bool => ! empty($get('sales_order_id')))
                            ->dehydrated(),
                        DatePicker::make('delivered_at')
                            ->nullable(),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
