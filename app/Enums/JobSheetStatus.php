<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum JobSheetStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Invoiced = 'invoiced';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Scheduled => __('Scheduled'),
            self::InProgress => __('In Progress'),
            self::OnHold => __('On Hold'),
            self::Completed => __('Completed'),
            self::Invoiced => __('Invoiced'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'info',
            self::InProgress => 'primary',
            self::OnHold => 'warning',
            self::Completed => 'success',
            self::Invoiced => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil->value,
            self::Scheduled => Heroicon::OutlinedCalendar->value,
            self::InProgress => Heroicon::OutlinedWrench->value,
            self::OnHold => Heroicon::OutlinedPause->value,
            self::Completed => Heroicon::OutlinedCheckCircle->value,
            self::Invoiced => Heroicon::OutlinedDocumentText->value,
        };
    }
}
