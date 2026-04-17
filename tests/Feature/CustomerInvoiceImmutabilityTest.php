<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\VatScenario;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;

/**
 * F-031: confirmed invoices and their economic item inputs are immutable.
 * Lifecycle fields (status, amount_paid, amount_due) and derived totals
 * remain mutable so payment flows and service-layer recalcs still work.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->vatRegistered()->create();
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

test('throws when updating a FROZEN field on a Confirmed invoice', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'invoice_number' => '0000000001',
        ]);

        expect(fn () => $invoice->update(['invoice_number' => '0000000002']))
            ->toThrow(RuntimeException::class, 'immutable');
    });
});

test('throws when updating vat_scenario on a Confirmed invoice', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'vat_scenario' => VatScenario::Domestic,
        ]);

        expect(fn () => $invoice->update(['vat_scenario' => VatScenario::NonEuExport]))
            ->toThrow(RuntimeException::class);
    });
});

test('allows status transition on a Confirmed invoice (Confirmed → Cancelled)', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
        ]);

        $invoice->update(['status' => DocumentStatus::Cancelled]);

        expect($invoice->fresh()->status)->toBe(DocumentStatus::Cancelled);
    });
});

test('allows payment updates on a Confirmed invoice (amount_paid / amount_due)', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'total' => '100.00',
            'amount_paid' => '0.00',
            'amount_due' => '100.00',
        ]);

        $invoice->update(['amount_paid' => '50.00', 'amount_due' => '50.00']);

        expect($invoice->fresh()->amount_paid)->toEqual('50.00')
            ->and($invoice->fresh()->amount_due)->toEqual('50.00');
    });
});

test('allows derived totals recompute on a Confirmed invoice', function () {
    // Post-confirmation derived totals may still be recomputed (advance-payment
    // deduction flow depends on this); economic inputs on items are the gate.
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        $invoice->update(['subtotal' => '90.00', 'tax_amount' => '18.00', 'total' => '108.00']);

        expect($invoice->fresh()->total)->toEqual('108.00');
    });
});

test('throws when deleting a Confirmed invoice', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
        ]);

        expect(fn () => $invoice->delete())
            ->toThrow(RuntimeException::class, 'Cannot delete a non-Draft invoice');
    });
});

test('allows editing and soft-deleting a Draft invoice', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Draft,
        ]);

        $invoice->update(['notes' => 'edited on draft']);
        expect($invoice->fresh()->notes)->toBe('edited on draft');

        $invoice->delete();
        // SoftDeletes: row still exists with deleted_at set but default scope hides it.
        expect(CustomerInvoice::withTrashed()->find($invoice->id))->not->toBeNull()
            ->and(CustomerInvoice::withTrashed()->find($invoice->id)->trashed())->toBeTrue();
    });
});

test('Draft → Confirmed transition is allowed (not blocked by the guard)', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Draft,
            'vat_scenario' => null,
        ]);

        $invoice->update([
            'status' => DocumentStatus::Confirmed,
            'vat_scenario' => VatScenario::Domestic,
        ]);

        expect($invoice->fresh()->status)->toBe(DocumentStatus::Confirmed)
            ->and($invoice->fresh()->vat_scenario)->toBe(VatScenario::Domestic);
    });
});

test('throws when updating FROZEN item field on a Confirmed invoice', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
        ]);
        $item = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
        ]);

        expect(fn () => $item->update(['unit_price' => '200.0000']))
            ->toThrow(RuntimeException::class, 'economic inputs');
    });
});

test('allows derived item totals recompute on Confirmed invoice', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
        ]);
        $item = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'line_total' => '100.00',
            'vat_amount' => '20.00',
        ]);

        $item->update(['line_total' => '100.00', 'vat_amount' => '20.00', 'line_total_with_vat' => '120.00']);

        expect($item->fresh()->line_total_with_vat)->toEqual('120.00');
    });
});

test('throws when deleting item of a Confirmed invoice', function () {
    $this->tenant->run(function () {
        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'status' => DocumentStatus::Confirmed,
        ]);
        $item = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
        ]);

        expect(fn () => $item->delete())
            ->toThrow(RuntimeException::class, 'Cannot delete items of a non-Draft invoice');
    });
});
