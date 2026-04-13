<?php

namespace App\Filament\Resources\GoodsReceivedNotes\Schemas;

use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class GoodsReceivedNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Goods Receipt')
                    ->columns(2)
                    ->schema([
                        TextInput::make('grn_number')
                            ->label('GRN Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('purchase_order_id')
                            ->label('Purchase Order (optional)')
                            ->options(
                                PurchaseOrder::whereIn('status', ['confirmed', 'partially_received'])
                                    ->with('partner')
                                    ->get()
                                    ->mapWithKeys(fn (PurchaseOrder $po) => [
                                        $po->id => "{$po->po_number} — {$po->partner->name}",
                                    ])
                            )
                            ->searchable()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $po = PurchaseOrder::with('partner')->find($state);
                                    if ($po) {
                                        $set('partner_id', $po->partner_id);
                                        if ($po->warehouse_id) {
                                            $set('warehouse_id', $po->warehouse_id);
                                        }
                                    }
                                }
                            }),
                        Select::make('partner_id')
                            ->label('Supplier')
                            ->options(
                                Partner::suppliers()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get): bool => ! empty($get('purchase_order_id')))
                            ->dehydrated(),
                        Select::make('warehouse_id')
                            ->label('Receiving Warehouse')
                            ->options(Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        DatePicker::make('received_at')
                            ->default(now()->toDateString()),
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
