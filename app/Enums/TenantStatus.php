<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum TenantStatus: string implements HasColor, HasIcon, HasLabel
{
    case Active = 'active';
    case Suspended = 'suspended';
    case MarkedForDeletion = 'marked_for_deletion';
    case ScheduledForDeletion = 'scheduled_for_deletion';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
            self::MarkedForDeletion => __('Marked for Deletion'),
            self::ScheduledForDeletion => __('Scheduled for Deletion'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::MarkedForDeletion => 'danger',
            self::ScheduledForDeletion => 'danger',
        };
    }

    public function getIcon(): string|Heroicon|null
    {
        return match ($this) {
            self::Active => Heroicon::OutlinedCheckCircle,
            self::Suspended => Heroicon::OutlinedPause,
            self::MarkedForDeletion => Heroicon::OutlinedExclamationTriangle,
            self::ScheduledForDeletion => Heroicon::OutlinedXCircle,
        };
    }

    /**
     * Returns whether this status can transition to the given target status.
     *
     * Active → Suspended (admin deactivation)
     * Active → ScheduledForDeletion (tenant-requested deletion, skips mark step)
     * Suspended → MarkedForDeletion (3 months unpaid)
     * Suspended → Active (reactivation after payment)
     * MarkedForDeletion → ScheduledForDeletion (5 months unpaid)
     * MarkedForDeletion → Active (reactivation during grace period)
     * ScheduledForDeletion → Active (emergency reactivation before auto-delete runs)
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Active => in_array($target, [self::Suspended, self::ScheduledForDeletion], true),
            self::Suspended => in_array($target, [self::Active, self::MarkedForDeletion], true),
            self::MarkedForDeletion => in_array($target, [self::Active, self::ScheduledForDeletion], true),
            self::ScheduledForDeletion => $target === self::Active,
        };
    }
}
