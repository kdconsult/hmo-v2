<?php

namespace App\Filament\Resources\Partners\Schemas;

use App\Enums\VatStatus;
use App\Models\Partner;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartnerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('VAT Status')
                    ->schema([
                        TextEntry::make('vies_staleness_warning')
                            ->label('')
                            ->state(function (Partner $record): ?string {
                                if ($record->vat_status !== VatStatus::Pending) {
                                    return null;
                                }
                                if (! $record->vies_last_checked_at || $record->vies_last_checked_at->gt(now()->subDays(7))) {
                                    return null;
                                }

                                $days = (int) $record->vies_last_checked_at->diffInDays(now());

                                return "VIES has been unavailable for this partner for {$days} days. Re-check now or escalate.";
                            })
                            ->color('warning')
                            ->columnSpanFull()
                            ->visible(fn (Partner $record): bool => $record->vat_status === VatStatus::Pending
                                && ($record->vies_last_checked_at === null || $record->vies_last_checked_at->lt(now()->subDays(7)))),
                    ])
                    ->visible(fn (Partner $record): bool => $record->vat_status === VatStatus::Pending
                        && ($record->vies_last_checked_at === null || $record->vies_last_checked_at->lt(now()->subDays(7)))),
            ]);
    }
}
