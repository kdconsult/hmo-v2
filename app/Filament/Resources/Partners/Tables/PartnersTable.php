<?php

namespace App\Filament\Resources\Partners\Tables;

use App\Enums\PartnerType;
use App\Enums\VatStatus;
use App\Models\Partner;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('vat_status_icon')
                    ->label('')
                    ->icon(fn (Partner $record): ?string => match ($record->vat_status) {
                        VatStatus::Confirmed => 'heroicon-s-check-badge',
                        VatStatus::Pending => 'heroicon-s-clock',
                        VatStatus::NotRegistered => null,
                        default => null,
                    })
                    ->color(fn (Partner $record): string => match ($record->vat_status) {
                        VatStatus::Confirmed => 'success',
                        VatStatus::Pending => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (Partner $record): ?string => $record->vat_status?->value),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('eik')
                    ->label('EIK')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vat_number')
                    ->label('VAT No.')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_customer')
                    ->label('Customer')
                    ->boolean(),
                IconColumn::make('is_supplier')
                    ->label('Supplier')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->searchDebounce('500ms')
            ->filters([
                SelectFilter::make('type')
                    ->options(PartnerType::class),
                TernaryFilter::make('is_customer')
                    ->label('Customer'),
                TernaryFilter::make('is_supplier')
                    ->label('Supplier'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
