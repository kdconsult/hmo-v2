<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Enums\VatStatus;
use App\Filament\Resources\Partners\Concerns\HandlesPartnerViesCheck;
use App\Filament\Resources\Partners\PartnerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPartner extends EditRecord
{
    use HandlesPartnerViesCheck;

    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $data = $this->form->getState();
        $isVatRegistered = (bool) ($data['is_vat_registered'] ?? false);
        $vatNumber = $data['vat_number'] ?? null;
        $vatStatus = $data['vat_status'] ?? VatStatus::NotRegistered->value;

        // Block save if toggle is ON but no VIES check was run (not confirmed and not pending)
        if ($isVatRegistered && blank($vatNumber) && $vatStatus !== VatStatus::Pending->value) {
            Notification::make()->danger()
                ->title('VAT verification required')
                ->body('Verify the VAT number via VIES before saving.')
                ->send();
            $this->halt();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['vat_lookup']);

        // Derive vat_status and timestamps from is_vat_registered + vat_number
        if (! ($data['is_vat_registered'] ?? false)) {
            $data['vat_status'] = VatStatus::NotRegistered->value;
            $data['vat_number'] = null;
            $data['vies_verified_at'] = null;
            $data['vies_last_checked_at'] = null;
        } elseif (! blank($data['vat_number'] ?? null)) {
            // Only update vies_verified_at if the vat_number changed from what's stored
            $currentVatNumber = $this->record->vat_number;
            if ($data['vat_number'] !== $currentVatNumber) {
                $data['vies_verified_at'] = now();
                $data['vies_last_checked_at'] = now();
            }
            $data['vat_status'] = VatStatus::Confirmed->value;
        } else {
            $data['vat_status'] = VatStatus::Pending->value;
            $data['vies_last_checked_at'] = now();
            $data['vies_verified_at'] = $this->record->vies_verified_at;
        }

        return $data;
    }
}
