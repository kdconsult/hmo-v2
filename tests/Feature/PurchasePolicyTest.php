<?php

declare(strict_types=1);

use App\Models\GoodsReceivedNote;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Policies\GoodsReceivedNotePolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\PurchaseReturnPolicy;
use App\Policies\SupplierCreditNotePolicy;
use App\Policies\SupplierInvoicePolicy;
use App\Services\TenantOnboardingService;

test('PurchaseOrderPolicy returns false without tenant context', function () {
    $user = User::factory()->create();
    $policy = new PurchaseOrderPolicy;

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->create($user))->toBeFalse();
});

test('purchasing-manager has full CRUD on all purchase documents', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        TenantUser::where('user_id', $user->id)->first()->syncRoles(['purchasing-manager']);

        $poPolicy = new PurchaseOrderPolicy;
        $po = new PurchaseOrder;

        expect($poPolicy->viewAny($user))->toBeTrue()
            ->and($poPolicy->view($user, $po))->toBeTrue()
            ->and($poPolicy->create($user))->toBeTrue()
            ->and($poPolicy->update($user, $po))->toBeTrue()
            ->and($poPolicy->delete($user, $po))->toBeTrue();

        $grnPolicy = new GoodsReceivedNotePolicy;
        $grn = new GoodsReceivedNote;

        expect($grnPolicy->viewAny($user))->toBeTrue()
            ->and($grnPolicy->create($user))->toBeTrue()
            ->and($grnPolicy->update($user, $grn))->toBeTrue();

        $siPolicy = new SupplierInvoicePolicy;
        $si = new SupplierInvoice;

        expect($siPolicy->viewAny($user))->toBeTrue()
            ->and($siPolicy->create($user))->toBeTrue()
            ->and($siPolicy->update($user, $si))->toBeTrue();

        $scnPolicy = new SupplierCreditNotePolicy;
        $scn = new SupplierCreditNote;

        expect($scnPolicy->viewAny($user))->toBeTrue()
            ->and($scnPolicy->create($user))->toBeTrue()
            ->and($scnPolicy->update($user, $scn))->toBeTrue();

        $prPolicy = new PurchaseReturnPolicy;
        $pr = new PurchaseReturn;

        expect($prPolicy->viewAny($user))->toBeTrue()
            ->and($prPolicy->create($user))->toBeTrue()
            ->and($prPolicy->update($user, $pr))->toBeTrue()
            ->and($prPolicy->delete($user, $pr))->toBeTrue();
    });
});

test('accountant can view POs and GRNs but has full CRUD on supplier invoices and credit notes', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        TenantUser::where('user_id', $user->id)->first()->syncRoles(['accountant']);

        $poPolicy = new PurchaseOrderPolicy;
        $po = new PurchaseOrder;

        expect($poPolicy->viewAny($user))->toBeTrue()
            ->and($poPolicy->view($user, $po))->toBeTrue()
            ->and($poPolicy->create($user))->toBeFalse();

        $grnPolicy = new GoodsReceivedNotePolicy;
        $grn = new GoodsReceivedNote;

        expect($grnPolicy->viewAny($user))->toBeTrue()
            ->and($grnPolicy->create($user))->toBeFalse();

        $siPolicy = new SupplierInvoicePolicy;
        $si = new SupplierInvoice;

        expect($siPolicy->viewAny($user))->toBeTrue()
            ->and($siPolicy->create($user))->toBeTrue()
            ->and($siPolicy->delete($user, $si))->toBeTrue();

        $scnPolicy = new SupplierCreditNotePolicy;
        $scn = new SupplierCreditNote;

        expect($scnPolicy->viewAny($user))->toBeTrue()
            ->and($scnPolicy->create($user))->toBeTrue();

        $prPolicy = new PurchaseReturnPolicy;
        $pr = new PurchaseReturn;

        expect($prPolicy->viewAny($user))->toBeTrue()
            ->and($prPolicy->view($user, $pr))->toBeTrue()
            ->and($prPolicy->create($user))->toBeFalse();
    });
});

test('warehouse-manager has CRUD on GRNs and view-only on POs', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        TenantUser::where('user_id', $user->id)->first()->syncRoles(['warehouse-manager']);

        $poPolicy = new PurchaseOrderPolicy;
        $po = new PurchaseOrder;

        expect($poPolicy->viewAny($user))->toBeTrue()
            ->and($poPolicy->view($user, $po))->toBeTrue()
            ->and($poPolicy->create($user))->toBeFalse();

        $grnPolicy = new GoodsReceivedNotePolicy;
        $grn = new GoodsReceivedNote;

        expect($grnPolicy->viewAny($user))->toBeTrue()
            ->and($grnPolicy->create($user))->toBeTrue()
            ->and($grnPolicy->update($user, $grn))->toBeTrue()
            ->and($grnPolicy->delete($user, $grn))->toBeTrue();

        // No access to supplier invoices
        $siPolicy = new SupplierInvoicePolicy;

        expect($siPolicy->viewAny($user))->toBeFalse()
            ->and($siPolicy->create($user))->toBeFalse();

        $prPolicy = new PurchaseReturnPolicy;
        $pr = new PurchaseReturn;

        expect($prPolicy->viewAny($user))->toBeTrue()
            ->and($prPolicy->create($user))->toBeTrue()
            ->and($prPolicy->update($user, $pr))->toBeTrue()
            ->and($prPolicy->delete($user, $pr))->toBeTrue();
    });
});
