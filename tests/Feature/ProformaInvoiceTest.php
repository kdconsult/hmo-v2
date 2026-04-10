<?php

declare(strict_types=1);

use App\Mail\ProformaInvoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;

function proformaPlan(): Plan
{
    return Plan::create([
        'name' => 'Professional', 'slug' => 'pro-proforma-'.uniqid(),
        'price' => 49.00, 'billing_period' => 'monthly',
        'is_active' => true, 'sort_order' => 3,
    ]);
}

beforeEach(function () {
    Tenant::clearLandlordTenantCache();
});

test('proforma email contains landlord bank details from tenant record', function () {
    $landlord = Tenant::factory()->create([
        'iban' => 'BG80BNBG96611020345678',
        'bic' => 'BNBGBGSD',
        'bank_name' => 'Тест Банк АД',
    ]);
    config(['hmo.landlord_tenant_id' => $landlord->id]);
    Tenant::clearLandlordTenantCache();

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $plan = proformaPlan();

    $mailable = new ProformaInvoice($tenant, $user, $plan);

    $mailable->assertSeeInHtml('BG80BNBG96611020345678');
    $mailable->assertSeeInHtml('BNBGBGSD');
    $mailable->assertSeeInHtml('Тест Банк АД');
});

test('proforma email shows dashes when landlord tenant has no bank details', function () {
    $landlord = Tenant::factory()->create(['iban' => null, 'bic' => null, 'bank_name' => null]);
    config(['hmo.landlord_tenant_id' => $landlord->id]);
    Tenant::clearLandlordTenantCache();

    $mailable = new ProformaInvoice(
        Tenant::factory()->create(),
        User::factory()->create(),
        proformaPlan()
    );

    $mailable->assertSeeInHtml('—');
});

test('proforma email works gracefully when landlord tenant is not configured', function () {
    config(['hmo.landlord_tenant_id' => null]);
    Tenant::clearLandlordTenantCache();

    $mailable = new ProformaInvoice(
        Tenant::factory()->create(),
        User::factory()->create(),
        proformaPlan()
    );

    // Should not throw; bank fields show dashes
    $mailable->assertSeeInHtml('—');
});

test('proforma mailable resolves landlord tenant company name', function () {
    $landlord = Tenant::factory()->create(['name' => 'Моята Фирма ООД']);
    config(['hmo.landlord_tenant_id' => $landlord->id]);
    Tenant::clearLandlordTenantCache();

    $mailable = new ProformaInvoice(
        Tenant::factory()->create(),
        User::factory()->create(),
        proformaPlan()
    );

    expect($mailable->landlordTenant->name)->toBe('Моята Фирма ООД');
});
