<?php

use App\Enums\ContractStatus;
use App\Enums\DocumentStatus;
use App\Enums\JobSheetStatus;
use App\Enums\NavigationGroup;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TenantStatus;
use App\Enums\TransferStatus;
use Filament\Support\Icons\Heroicon;

it('returns Heroicon enum instances for Filament iconable enums', function ($case, Heroicon $expectedIcon) {
    expect($case->getIcon())->toBe($expectedIcon);
})->with([
    'contract status' => [ContractStatus::Active, Heroicon::OutlinedCheckCircle],
    'document status' => [DocumentStatus::Confirmed, Heroicon::OutlinedCheckCircle],
    'job sheet status' => [JobSheetStatus::Completed, Heroicon::OutlinedCheckCircle],
    'navigation group' => [NavigationGroup::Dashboard, Heroicon::OutlinedHome],
    'sales order status' => [SalesOrderStatus::Confirmed, Heroicon::OutlinedCheckCircle],
    'payment method' => [PaymentMethod::Cash, Heroicon::OutlinedBanknotes],
    'purchase order status' => [PurchaseOrderStatus::Confirmed, Heroicon::OutlinedCheckCircle],
    'quotation status' => [QuotationStatus::Accepted, Heroicon::OutlinedHandThumbUp],
    'tenant status' => [TenantStatus::Active, Heroicon::OutlinedCheckCircle],
    'transfer status' => [TransferStatus::Received, Heroicon::OutlinedCheckBadge],
]);
