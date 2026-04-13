<?php

namespace App\Filament\Resources\PurchaseReturns\Schemas;

use App\Models\GoodsReceivedNote;
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

class PurchaseReturnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Purchase Return')
                    ->columns(2)
                    ->schema([
                        TextInput::make('pr_number')
                            ->label('PR Number')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on save')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Select::make('goods_received_note_id')
                            ->label('Goods Receipt (optional)')
                            ->options(
                                GoodsReceivedNote::where('status', 'confirmed')
                                    ->with('partner')
                                    ->get()
                                    ->mapWithKeys(fn (GoodsReceivedNote $grn) => [
                                        $grn->id => "{$grn->grn_number} — {$grn->partner->name}",
                                    ])
                            )
                            ->searchable()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state) {
                                    $grn = GoodsReceivedNote::find($state);
                                    if ($grn) {
                                        $set('partner_id', $grn->partner_id);
                                        $set('warehouse_id', $grn->warehouse_id);
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
                            ->disabled(fn (Get $get): bool => ! empty($get('goods_received_note_id')))
                            ->dehydrated(),
                        Select::make('warehouse_id')
                            ->label('Warehouse')
                            ->options(Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get): bool => ! empty($get('goods_received_note_id')))
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
