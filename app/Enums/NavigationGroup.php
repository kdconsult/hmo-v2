<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum NavigationGroup: string implements HasIcon, HasLabel
{
    case Dashboard = 'dashboard';
    case Crm = 'crm';
    case Catalog = 'catalog';
    case Sales = 'sales';
    case Purchases = 'purchases';
    case Warehouse = 'warehouse';
    case FieldService = 'field_service';
    case Finance = 'finance';
    case Fiscal = 'fiscal';
    case Reports = 'reports';
    case Settings = 'settings';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dashboard => __('Dashboard'),
            self::Crm => __('CRM'),
            self::Catalog => __('Catalog'),
            self::Sales => __('Sales'),
            self::Purchases => __('Purchases'),
            self::Warehouse => __('Warehouse'),
            self::FieldService => __('Field Service'),
            self::Finance => __('Finance'),
            self::Fiscal => __('Fiscal'),
            self::Reports => __('Reports'),
            self::Settings => __('Settings'),
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Dashboard => Heroicon::OutlinedHome->value,
            self::Crm => Heroicon::OutlinedUsers->value,
            self::Catalog => Heroicon::OutlinedCube->value,
            self::Sales => Heroicon::OutlinedShoppingCart->value,
            self::Purchases => Heroicon::OutlinedInboxArrowDown->value,
            self::Warehouse => Heroicon::OutlinedBuildingStorefront->value,
            self::FieldService => Heroicon::OutlinedWrenchScrewdriver->value,
            self::Finance => Heroicon::OutlinedBanknotes->value,
            self::Fiscal => Heroicon::OutlinedShieldCheck->value,
            self::Reports => Heroicon::OutlinedChartBarSquare->value,
            self::Settings => Heroicon::OutlinedCog6Tooth->value,
        };
    }
}
