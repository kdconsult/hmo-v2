<?php

namespace App\Filament\Resources\CustomerDebitNotes\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerDebitNotes\CustomerDebitNoteResource;
use App\Models\CustomerDebitNote;
use App\Services\CustomerDebitNoteService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomerDebitNote extends ViewRecord
{
    protected static string $resource = CustomerDebitNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (CustomerDebitNote $record): bool => $record->isEditable()),

            Action::make('confirm')
                ->label('Confirm Debit Note')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (CustomerDebitNote $record): bool => $record->status === DocumentStatus::Draft)
                ->action(function (CustomerDebitNote $record): void {
                    app(CustomerDebitNoteService::class)->confirm($record);
                    Notification::make()->title('Debit note confirmed')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (CustomerDebitNote $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (CustomerDebitNote $record): void {
                    app(CustomerDebitNoteService::class)->cancel($record);
                    Notification::make()->title('Debit note cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
