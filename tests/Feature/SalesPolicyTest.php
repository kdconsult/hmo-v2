<?php

declare(strict_types=1);

use App\Models\AdvancePayment;
use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\DeliveryNote;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Policies\AdvancePaymentPolicy;
use App\Policies\CustomerCreditNotePolicy;
use App\Policies\CustomerDebitNotePolicy;
use App\Policies\CustomerInvoicePolicy;
use App\Policies\DeliveryNotePolicy;
use App\Policies\QuotationPolicy;
use App\Policies\SalesOrderPolicy;
use App\Policies\SalesReturnPolicy;
use App\Services\TenantOnboardingService;

test('SalesOrderPolicy returns false without tenant context', function () {
    $user = User::factory()->create();
    $policy = new SalesOrderPolicy;

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->create($user))->toBeFalse();
});

test('sales-manager has full CRUD on all sales documents', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        TenantUser::where('user_id', $user->id)->first()->syncRoles(['sales-manager']);

        $qPolicy = new QuotationPolicy;
        $q = new Quotation;
        expect($qPolicy->viewAny($user))->toBeTrue()
            ->and($qPolicy->create($user))->toBeTrue()
            ->and($qPolicy->update($user, $q))->toBeTrue()
            ->and($qPolicy->delete($user, $q))->toBeTrue();

        $soPolicy = new SalesOrderPolicy;
        $so = new SalesOrder;
        expect($soPolicy->viewAny($user))->toBeTrue()
            ->and($soPolicy->create($user))->toBeTrue()
            ->and($soPolicy->update($user, $so))->toBeTrue()
            ->and($soPolicy->delete($user, $so))->toBeTrue();

        $dnPolicy = new DeliveryNotePolicy;
        $dn = new DeliveryNote;
        expect($dnPolicy->viewAny($user))->toBeTrue()
            ->and($dnPolicy->create($user))->toBeTrue()
            ->and($dnPolicy->update($user, $dn))->toBeTrue()
            ->and($dnPolicy->delete($user, $dn))->toBeTrue();

        $ciPolicy = new CustomerInvoicePolicy;
        $ci = new CustomerInvoice;
        expect($ciPolicy->viewAny($user))->toBeTrue()
            ->and($ciPolicy->create($user))->toBeTrue()
            ->and($ciPolicy->update($user, $ci))->toBeTrue()
            ->and($ciPolicy->delete($user, $ci))->toBeTrue();

        $ccnPolicy = new CustomerCreditNotePolicy;
        $ccn = new CustomerCreditNote;
        expect($ccnPolicy->viewAny($user))->toBeTrue()
            ->and($ccnPolicy->create($user))->toBeTrue()
            ->and($ccnPolicy->update($user, $ccn))->toBeTrue()
            ->and($ccnPolicy->delete($user, $ccn))->toBeTrue();

        $cdnPolicy = new CustomerDebitNotePolicy;
        $cdn = new CustomerDebitNote;
        expect($cdnPolicy->viewAny($user))->toBeTrue()
            ->and($cdnPolicy->create($user))->toBeTrue()
            ->and($cdnPolicy->update($user, $cdn))->toBeTrue()
            ->and($cdnPolicy->delete($user, $cdn))->toBeTrue();

        $srPolicy = new SalesReturnPolicy;
        $sr = new SalesReturn;
        expect($srPolicy->viewAny($user))->toBeTrue()
            ->and($srPolicy->create($user))->toBeTrue()
            ->and($srPolicy->update($user, $sr))->toBeTrue()
            ->and($srPolicy->delete($user, $sr))->toBeTrue();

        $apPolicy = new AdvancePaymentPolicy;
        $ap = new AdvancePayment;
        expect($apPolicy->viewAny($user))->toBeTrue()
            ->and($apPolicy->create($user))->toBeTrue()
            ->and($apPolicy->update($user, $ap))->toBeTrue()
            ->and($apPolicy->delete($user, $ap))->toBeTrue();
    });
});

test('accountant has view-only on operational sales documents and full CRUD on financial documents', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        TenantUser::where('user_id', $user->id)->first()->syncRoles(['accountant']);

        // View-only on Quotation
        $qPolicy = new QuotationPolicy;
        $q = new Quotation;
        expect($qPolicy->viewAny($user))->toBeTrue()
            ->and($qPolicy->view($user, $q))->toBeTrue()
            ->and($qPolicy->create($user))->toBeFalse();

        // View-only on SalesOrder
        $soPolicy = new SalesOrderPolicy;
        $so = new SalesOrder;
        expect($soPolicy->viewAny($user))->toBeTrue()
            ->and($soPolicy->view($user, $so))->toBeTrue()
            ->and($soPolicy->create($user))->toBeFalse();

        // View-only on DeliveryNote
        $dnPolicy = new DeliveryNotePolicy;
        $dn = new DeliveryNote;
        expect($dnPolicy->viewAny($user))->toBeTrue()
            ->and($dnPolicy->create($user))->toBeFalse();

        // View-only on SalesReturn
        $srPolicy = new SalesReturnPolicy;
        $sr = new SalesReturn;
        expect($srPolicy->viewAny($user))->toBeTrue()
            ->and($srPolicy->create($user))->toBeFalse();

        // Full CRUD on CustomerInvoice
        $ciPolicy = new CustomerInvoicePolicy;
        $ci = new CustomerInvoice;
        expect($ciPolicy->viewAny($user))->toBeTrue()
            ->and($ciPolicy->create($user))->toBeTrue()
            ->and($ciPolicy->delete($user, $ci))->toBeTrue();

        // Full CRUD on CustomerCreditNote
        $ccnPolicy = new CustomerCreditNotePolicy;
        $ccn = new CustomerCreditNote;
        expect($ccnPolicy->viewAny($user))->toBeTrue()
            ->and($ccnPolicy->create($user))->toBeTrue()
            ->and($ccnPolicy->delete($user, $ccn))->toBeTrue();

        // Full CRUD on CustomerDebitNote
        $cdnPolicy = new CustomerDebitNotePolicy;
        $cdn = new CustomerDebitNote;
        expect($cdnPolicy->viewAny($user))->toBeTrue()
            ->and($cdnPolicy->create($user))->toBeTrue()
            ->and($cdnPolicy->delete($user, $cdn))->toBeTrue();

        // Full CRUD on AdvancePayment
        $apPolicy = new AdvancePaymentPolicy;
        $ap = new AdvancePayment;
        expect($apPolicy->viewAny($user))->toBeTrue()
            ->and($apPolicy->create($user))->toBeTrue()
            ->and($apPolicy->delete($user, $ap))->toBeTrue();
    });
});

test('warehouse-manager has CRUD on delivery notes and sales returns and view-only on sales orders', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () use ($user) {
        TenantUser::where('user_id', $user->id)->first()->syncRoles(['warehouse-manager']);

        // No access to Quotation
        $qPolicy = new QuotationPolicy;
        expect($qPolicy->viewAny($user))->toBeFalse()
            ->and($qPolicy->create($user))->toBeFalse();

        // View-only on SalesOrder
        $soPolicy = new SalesOrderPolicy;
        $so = new SalesOrder;
        expect($soPolicy->viewAny($user))->toBeTrue()
            ->and($soPolicy->view($user, $so))->toBeTrue()
            ->and($soPolicy->create($user))->toBeFalse();

        // Full CRUD on DeliveryNote
        $dnPolicy = new DeliveryNotePolicy;
        $dn = new DeliveryNote;
        expect($dnPolicy->viewAny($user))->toBeTrue()
            ->and($dnPolicy->create($user))->toBeTrue()
            ->and($dnPolicy->update($user, $dn))->toBeTrue()
            ->and($dnPolicy->delete($user, $dn))->toBeTrue();

        // Full CRUD on SalesReturn
        $srPolicy = new SalesReturnPolicy;
        $sr = new SalesReturn;
        expect($srPolicy->viewAny($user))->toBeTrue()
            ->and($srPolicy->create($user))->toBeTrue()
            ->and($srPolicy->update($user, $sr))->toBeTrue()
            ->and($srPolicy->delete($user, $sr))->toBeTrue();

        // No access to CustomerInvoice
        $ciPolicy = new CustomerInvoicePolicy;
        expect($ciPolicy->viewAny($user))->toBeFalse()
            ->and($ciPolicy->create($user))->toBeFalse();

        // No access to AdvancePayment
        $apPolicy = new AdvancePaymentPolicy;
        expect($apPolicy->viewAny($user))->toBeFalse()
            ->and($apPolicy->create($user))->toBeFalse();
    });
});
