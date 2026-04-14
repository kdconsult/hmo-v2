<?php

namespace App\Filament\Resources\SalesReturns\Schemas;

use App\Models\DeliveryNote;
use App\Models\Partner;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SalesReturnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sales Return')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sr_number')
                            ->label('SR Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('delivery_note_id')
                            ->label('Delivery Note (optional)')
                            ->options(
                                DeliveryNote::where('status', 'confirmed')
                                    ->with('partner')
                                    ->get()
                                    ->mapWithKeys(fn (DeliveryNote $dn) => [
                                        $dn->id => "{$dn->dn_number} — {$dn->partner->name}",
                                    ])
                            )
                            ->searchable()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $dn = DeliveryNote::find($state);
                                    if ($dn) {
                                        $set('partner_id', $dn->partner_id);
                                        $set('warehouse_id', $dn->warehouse_id);
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
                            ->disabled(fn (Get $get): bool => ! empty($get('delivery_note_id')))
                            ->dehydrated(),
                        Select::make('warehouse_id')
                            ->label('Warehouse')
                            ->options(Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get): bool => ! empty($get('delivery_note_id')))
                            ->dehydrated(),
                        DatePicker::make('returned_at')
                            ->label('Return Date')
                            ->default(now()->toDateString()),
                        Textarea::make('reason')
                            ->label('Return Reason')
                            ->rows(3)
                            ->columnSpanFull(),
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
