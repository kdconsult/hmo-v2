<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Enums\VatStatus;
use App\Events\DataSubjectRequestReceived;
use App\Filament\Resources\Partners\Concerns\HandlesPartnerViesCheck;
use App\Filament\Resources\Partners\PartnerResource;
use App\Services\PartnerVatService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPartner extends ViewRecord
{
    use HandlesPartnerViesCheck;

    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            Action::make('data_subject_request')
                ->label('Data Subject Request')
                ->icon(Heroicon::ShieldCheck)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Log GDPR Data Subject Request')
                ->modalDescription('This will record a data subject access request (GDPR Art. 15) for this partner in the activity log.')
                ->action(function (): void {
                    $partner = $this->record;
                    $userId = auth()->id();

                    activity()
                        ->performedOn($partner)
                        ->causedBy(auth()->user())
                        ->withProperties(['type' => 'dsar', 'gdpr_article' => 'Art. 15'])
                        ->log('data_subject_request');

                    DataSubjectRequestReceived::dispatch($partner, $userId);

                    Notification::make()
                        ->title('Data subject request logged')
                        ->body('The request has been recorded in the activity log.')
                        ->success()
                        ->send();
                }),

            Action::make('validate_vat')
                ->label('Validate VAT')
                ->icon(Heroicon::Bolt)
                ->visible(fn () => $this->record->vat_status === VatStatus::Confirmed)
                ->action(function (): void {
                    $result = app(PartnerVatService::class)->reVerify($this->record);

                    match ($result) {
                        VatStatus::Confirmed => Notification::make()->success()
                            ->title('VAT confirmed')
                            ->body("VIES confirms {$this->record->vat_number} is still valid.")
                            ->send(),
                        VatStatus::NotRegistered => Notification::make()->warning()
                            ->title('VAT number no longer valid')
                            ->body('Partner VAT status has been reset to not registered.')
                            ->send(),
                        default => Notification::make()->warning()
                            ->title('VIES service is unreachable')
                            ->body('Could not verify. Status unchanged.')
                            ->send(),
                    };

                    $this->refreshFormData([
                        'vat_status',
                        'vat_number',
                        'is_vat_registered',
                        'vies_verified_at',
                        'vies_last_checked_at',
                    ]);
                }),
        ];
    }
}
