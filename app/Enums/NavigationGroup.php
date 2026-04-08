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

    public function getIcon(): string|Heroicon|null
    {
        return match ($this) {
            self::Dashboard => Heroicon::OutlinedHome,
            self::Crm => Heroicon::OutlinedUsers,
            self::Catalog => Heroicon::OutlinedCube,
            self::Sales => Heroicon::OutlinedShoppingCart,
            self::Purchases => Heroicon::OutlinedInboxArrowDown,
            self::Warehouse => Heroicon::OutlinedBuildingStorefront,
            self::FieldService => Heroicon::OutlinedWrenchScrewdriver,
            self::Finance => Heroicon::OutlinedBanknotes,
            self::Fiscal => Heroicon::OutlinedShieldCheck,
            self::Reports => Heroicon::OutlinedChartBarSquare,
            self::Settings => Heroicon::OutlinedCog6Tooth,
        };
    }
}
