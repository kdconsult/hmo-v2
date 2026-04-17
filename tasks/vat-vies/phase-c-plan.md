# Phase C — Credit & Debit Note VAT Determination + Blocks

## Context

Credit and debit notes correct a previously confirmed customer invoice.
Their VAT treatment must **mirror the parent invoice** — the parent's
scenario is already a legal fact; the correction document cannot invent
a new one. Currently both services simply flip the status to Confirmed
and store no scenario data. There are no PDF templates and no
confirmation modals.

Phase C closes these gaps and mirrors Phase B's tenant-block behaviour
on the correction document types.

## Prerequisite

Phase B must land first. Phase C depends on:
- `VatScenario::DomesticExempt` (Phase B enum change)
- `vat_scenario_sub_code` column pattern (Phase B migration)
- `VatLegalReference::resolve()` semantics (Phase A)
- Confirmation modal schema pattern (Phase B conversion)
- `EuOssService` year-sourcing fix (Phase B — `issued_at->year`)

## Scope

1. **Migrations** — add `vat_scenario`, `vat_scenario_sub_code`,
   `is_reverse_charge` to `customer_credit_notes` and
   `customer_debit_notes`.
2. **Models** — `$fillable` + `casts` updates on both models.
3. **Services** — `confirmWithScenario()` on both services.
   - **Credit note service**: parent always present (schema
     `restrictOnDelete`); no standalone path; throw defensively if null.
   - **Debit note service**: parent nullable; falls back to
     `VatScenario::determine()` when standalone.
   - Both: short-circuit to Exempt when tenant non-registered.
   - **OSS adjustment**: when the parent invoice's scenario is
     `EuB2cOverThreshold`, call the new
     `EuOssService::adjust(CustomerInvoice $parent, float $deltaEur)`
     method (added in Phase C) to keep the OSS ledger in sync. Credit
     note → negative delta sized to the note total; debit note →
     positive delta. `adjust()` derives the year from
     `$parent->issued_at->year` — same sourcing Phase B installs in
     accumulate/reverseAccumulation. Standalone debit notes routing
     to `EuB2cOverThreshold` via `determine()` do **not** accumulate
     fresh OSS in Phase C — deferred to the OSS-hardening follow-up
     because fresh accumulation on a standalone correction document
     is a narrow edge case and wiring it would pull threshold-
     crossing logic into the debit-note path.
4. **Blocks on forms** — hide `pricing_mode` on both forms when tenant
   non-registered (mirrors Phase B).
5. **Items relation managers** — restrict `vat_rate_id` to 0% when
   tenant non-registered OR when note inherits a zero-rated scenario.
6. **Import from Invoice action** (verified present in both RMs —
   `CustomerCreditNoteItemsRelationManager.php:148-214` and
   `CustomerDebitNoteItemsRelationManager.php:160-223`) + per-line
   `afterStateUpdated` copy (`:72` credit, `:77` debit) — override item
   VAT rate to 0% when tenant non-registered (protects against legacy
   parent invoices carrying non-zero rates).
7. **Confirmation modals** — replace bare `->requiresConfirmation()` on
   both view pages with schema-based modals showing inherited scenario,
   article label, financial preview.
8. **PDF templates** — new
   `resources/views/pdf/customer-credit-note.blade.php` and
   `resources/views/pdf/customer-debit-note.blade.php`, based on the
   Phase B customer-invoice template with "CREDIT NOTE" / "DEBIT NOTE"
   headings and a parent-invoice reference line.
9. **Print actions** — add to both view pages.
10. **Tests** — cover inheritance, fallback, blocks, modals, PDFs.

## Critical Files

### Create

- `database/migrations/tenant/2026_04_17_220000_add_vat_to_customer_credit_notes.php`
- `database/migrations/tenant/2026_04_17_220001_add_vat_to_customer_debit_notes.php`
- `resources/views/pdf/customer-credit-note.blade.php`
- `resources/views/pdf/customer-debit-note.blade.php`
- `tests/Unit/Services/CustomerCreditNoteServiceTest.php`
- `tests/Unit/Services/CustomerDebitNoteServiceTest.php`
- `tests/Feature/Filament/CustomerCreditNoteConfirmTest.php`
- `tests/Feature/Filament/CustomerDebitNoteConfirmTest.php`
- `tests/Feature/Pdf/CustomerCreditNotePdfTest.php`
- `tests/Feature/Pdf/CustomerDebitNotePdfTest.php`

### Modify

- `app/Models/CustomerCreditNote.php`, `app/Models/CustomerDebitNote.php`
  — `$fillable` + `casts`.
- `app/Services/CustomerCreditNoteService.php`,
  `app/Services/CustomerDebitNoteService.php` — add
  `confirmWithScenario()`; keep existing `confirm()` as thin wrapper for
  backward compat.
- `app/Services/EuOssService.php` — add `adjust(CustomerInvoice $parent, float $deltaEur): void`.
  Applies the same eligibility guards as `accumulate()` (EU cross-border,
  non-B2B, partner country present) and books under
  `$parent->issued_at->year` (year-sourcing is Phase B's fix, reused here).
- `app/Filament/Resources/CustomerCreditNotes/Schemas/CustomerCreditNoteForm.php`,
  same for Debit — hide `pricing_mode` when tenant non-registered.
- Relation managers for credit and debit notes — restrict
  `vat_rate_id`; override import action.
- `app/Filament/Resources/CustomerCreditNotes/Pages/ViewCustomerCreditNote.php`,
  same for Debit — schema-based Confirm action + Print action.

### Reuse (do not re-implement)

- `VatScenario::determine()` — standalone fallback path only.
- `VatScenario::requiresVatRateChange()` — already covers DomesticExempt.
- `VatLegalReference::resolve()` — PDF legal-notice lookup.
- `CustomerInvoiceService::applyZeroRateToItems($invoice, $rate)` — the
  **new** private helper extracted during Phase B. Phase C duplicates
  the same structure inside each note service (copy — not share). Also
  duplicate a tiny `applyZeroRateToItems($note, $rate)` private method
  per note service that does the `loadMissing('items')` + `foreach` rate
  assignment + totals recalc against the note's own items relation.
  **Do not extract a shared service across CustomerInvoice/CreditNote/
  DebitNote** — the item relations differ enough that sharing adds more
  complexity than it removes. YAGNI.
- Confirmation schema pattern from
  `ViewCustomerInvoice::buildConfirmationSchema()`.
- Blade structure from `pdf/customer-invoice.blade.php`.

## Migrations

Each migration adds three columns:
```php
$table->string('vat_scenario')->nullable()->after('status');
$table->string('vat_scenario_sub_code')->nullable()->after('vat_scenario');
$table->boolean('is_reverse_charge')->default(false)->after('vat_scenario_sub_code');
```

No VIES audit columns — the parent invoice already carries them.

No backfill — correction notes in dev/test are unconfirmed or can be
recreated. Staging/prod: apply when customer-facing traffic is low.

## Models

Both models:
```php
protected $fillable = [
    // ... existing ...
    'vat_scenario',
    'vat_scenario_sub_code',
    'is_reverse_charge',
];

protected function casts(): array
{
    return [
        // ... existing ...
        'vat_scenario' => VatScenario::class,
        'is_reverse_charge' => 'boolean',
    ];
}
```

`vat_scenario_sub_code` stays a plain nullable string (matches Phase B).

## Service — `confirmWithScenario()`

The schema differs between the two note types:

| Table | `customer_invoice_id` |
|-------|-----------------------|
| `customer_credit_notes` | `constrained('customer_invoices')->restrictOnDelete()` — **NOT NULL** |
| `customer_debit_notes`  | `->nullable()->constrained('customer_invoices')->nullOnDelete()` — nullable |

The credit-note service therefore **always** has a parent; missing
parent is unreachable. The debit-note service has a standalone path.

### Credit note service — parent-only

```php
public function confirmWithScenario(CustomerCreditNote $note, ?string $subCodeHint = null): void
{
    DB::transaction(function () use ($note, $subCodeHint): void {
        $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;
        $tenantCountry = strtoupper((string) (tenancy()->tenant?->country_code ?? 'BG'));

        // Short-circuit for non-registered tenants — ignore parent scenario.
        if (! $tenantIsVatRegistered) {
            $zeroRate = $this->resolveZeroVatRate($tenantCountry);
            $this->applyZeroRateToItems($note, $zeroRate);
            $note->vat_scenario = VatScenario::Exempt;
            $note->vat_scenario_sub_code = 'default';
            $note->is_reverse_charge = false;
            $note->status = DocumentStatus::Confirmed;
            $note->save();
            return;
        }

        $parent = $note->customerInvoice; // schema guarantees non-null
        if ($parent === null) {
            // Defensive — schema NOT NULL should make this unreachable.
            throw new DomainException(
                'Credit note is missing its parent invoice — schema violation.'
            );
        }

        $note->vat_scenario = $parent->vat_scenario;
        $note->vat_scenario_sub_code = $parent->vat_scenario_sub_code;
        $note->is_reverse_charge = $parent->is_reverse_charge;

        if ($note->vat_scenario?->requiresVatRateChange()) {
            $zeroRate = $this->resolveZeroVatRate($tenantCountry);
            $this->applyZeroRateToItems($note, $zeroRate);
        }

        $note->status = DocumentStatus::Confirmed;
        $note->save();

        // OSS adjustment: if the parent invoice accumulated under
        // EuB2cOverThreshold, reverse the credited amount from the
        // parent's fiscal year bucket. adjust() derives the year from
        // $parent->issued_at->year (Phase B fix to accumulate/reverse).
        if ($parent->vat_scenario === VatScenario::EuB2cOverThreshold) {
            $deltaEur = $this->convertTotalToEur($note);
            app(EuOssService::class)->adjust($parent, -(float) $deltaEur);
        }
    });
}
```

`convertTotalToEur()` is a private helper mirroring the inline EUR
conversion in `EuOssService::accumulate()`:
```php
private function convertTotalToEur(CustomerCreditNote|CustomerDebitNote $doc): float
{
    return (float) (bccomp((string) $doc->exchange_rate, '0', 6) > 0
        ? bcdiv((string) $doc->total, (string) $doc->exchange_rate, 2)
        : (string) $doc->total);
}
```

### Debit note service — parent optional

Same shape as the credit-note service but with a standalone fallback
when `customerInvoice` is null. Standalone path calls
`VatScenario::determine()` with `tenantIsVatRegistered: true` (we only
reach this branch when the tenant IS registered — the earlier short-
circuit handled the `false` case):

```php
public function confirmWithScenario(CustomerDebitNote $note, ?string $subCodeHint = null): void
{
    DB::transaction(function () use ($note, $subCodeHint): void {
        $tenantIsVatRegistered = (bool) tenancy()->tenant?->is_vat_registered;
        $tenantCountry = strtoupper((string) (tenancy()->tenant?->country_code ?? 'BG'));

        if (! $tenantIsVatRegistered) {
            // Same short-circuit as credit note.
            $zeroRate = $this->resolveZeroVatRate($tenantCountry);
            $this->applyZeroRateToItems($note, $zeroRate);
            $note->vat_scenario = VatScenario::Exempt;
            $note->vat_scenario_sub_code = 'default';
            $note->is_reverse_charge = false;
            $note->status = DocumentStatus::Confirmed;
            $note->save();
            return;
        }

        $parent = $note->customerInvoice;
        if ($parent !== null) {
            // Inherit from parent, same as credit note.
            $note->vat_scenario = $parent->vat_scenario;
            $note->vat_scenario_sub_code = $parent->vat_scenario_sub_code;
            $note->is_reverse_charge = $parent->is_reverse_charge;

            if ($note->vat_scenario?->requiresVatRateChange()) {
                $zeroRate = $this->resolveZeroVatRate($tenantCountry);
                $this->applyZeroRateToItems($note, $zeroRate);
            }
        } else {
            // Standalone — debit-note only.
            $scenario = VatScenario::determine(
                $note->partner,
                $tenantCountry,
                ignorePartnerVat: false,
                tenantIsVatRegistered: true,
            );

            $note->vat_scenario = $scenario;
            $note->vat_scenario_sub_code = match ($scenario) {
                VatScenario::EuB2bReverseCharge, VatScenario::NonEuExport
                    => $subCodeHint ?? $this->inferSubCodeFromItems($note),
                default => null,
            };
            $note->is_reverse_charge = $scenario === VatScenario::EuB2bReverseCharge;

            if ($scenario->requiresVatRateChange()) {
                $zeroRate = $this->resolveZeroVatRate($tenantCountry);
                $this->applyZeroRateToItems($note, $zeroRate);
            }
        }

        $note->status = DocumentStatus::Confirmed;
        $note->save();

        // OSS top-up: if the parent invoice accumulated under
        // EuB2cOverThreshold, add the debit-note delta to the parent's
        // fiscal year bucket (positive sign). Standalone debit notes
        // do NOT accumulate fresh OSS in Phase C — that edge case is
        // deferred to the OSS-hardening follow-up.
        if ($parent !== null && $parent->vat_scenario === VatScenario::EuB2cOverThreshold) {
            $deltaEur = $this->convertTotalToEur($note);
            app(EuOssService::class)->adjust($parent, (float) $deltaEur);
        }
    });
}
```

### OSS adjustment on credit/debit notes — committed design

Phase C fixes two entangled OSS gaps:

- **Amount drift**: today neither credit-note nor debit-note services
  touch the OSS ledger, so issuing a credit note instead of cancelling
  a parent invoice that accumulated under `EuB2cOverThreshold` leaves
  the ledger overstated; a debit note understates it.
- **Year bucket**: `EuOssService::accumulate()` at
  `app/Services/EuOssService.php:72` and `reverseAccumulation()` at
  `:108` bucket under `(int) now()->year` — wrong for any cross-year
  correction (Dec invoice, Jan cancel reverses against the wrong year).

**Phase B** fixes the year bucket by changing those two lines to
`(int) $invoice->issued_at->year`. `CustomerInvoice::$issued_at` is
already cast as `'date'` (Carbon), so `->year` is safe — verified at
`app/Models/CustomerInvoice.php:78`. Existing callers
(`CustomerInvoiceService::confirmWithScenario()` and `::cancel()`) pick
up the fix transparently; Phase B adds a cross-year cancel regression
test.

**Phase C** adds `EuOssService::adjust(CustomerInvoice $parent, float $deltaEur): void`
— a partial-amount helper that applies the same eligibility checks as
`accumulate()` (EU cross-border, non-B2B, parent's country still valid)
and books `$deltaEur` under `$parent->issued_at->year`. Credit-note
service passes a negative delta sized to the note's EUR-converted total;
debit-note service passes a positive delta. Sign convention mirrors
`reverseAccumulation()`.

Standalone debit notes routing to `EuB2cOverThreshold` via
`VatScenario::determine()` do **not** accumulate fresh OSS in Phase C.
That edge case (a tenant issuing a standalone debit note that itself
crosses the OSS threshold) is deferred to the OSS-hardening follow-up
— wiring it would pull threshold-crossing logic into the debit-note
service, which is outside Phase C's scope.

`applyZeroRateToItems(Model $doc, VatRate $rate): void`,
`resolveZeroVatRate(string $countryCode): VatRate`,
`inferSubCodeFromItems(Model $doc): string`, and
`convertTotalToEur(Model $doc): float` are private methods in each
service — literal copies of Phase B's `CustomerInvoiceService`
implementations, retyped against the note's own items relation. No
shared trait or base class.

## Forms + Relation Managers + Import Action

**Verified state (read at plan time):**

- `CustomerCreditNoteItemsRelationManager.php:118-122` — `vat_rate_id`
  Select currently uses `VatRate::active()->orderBy('rate')->pluck('name', 'id')`
  (all active rates).
- `CustomerCreditNoteItemsRelationManager.php:148-214` — `Action::make('import_from_invoice')`
  exists. At line 195 the imported row is created with
  `'vat_rate_id' => $invoiceItem->vat_rate_id` — copies parent rate verbatim,
  no exempt override.
- `CustomerCreditNoteItemsRelationManager.php:65-76` — the per-line
  `customer_invoice_item_id` Select's `afterStateUpdated` closure at
  line 72 also copies `vat_rate_id` from the picked invoice line. This
  is a second override site (same logic must apply).
- `CustomerDebitNoteItemsRelationManager.php:134-138` — same bare
  `VatRate::active()` for `vat_rate_id`.
- `CustomerDebitNoteItemsRelationManager.php:160-223` — same
  `Action::make('import_from_invoice')` with direct copy at line 206.
- `CustomerDebitNoteItemsRelationManager.php:70-80` — same
  `afterStateUpdated` copy site at line 77.

Mirrors Phase B exactly:
- `pricing_mode` hidden when `! $tenantIsVatRegistered`.
- `vat_rate_id` options restricted to 0% when non-registered OR when the
  owner note's `vat_scenario_sub_code` starts with `'art_'`. Replace the
  bare `VatRate::active()->...` options at
  `CustomerCreditNoteItemsRelationManager.php:120` and
  `CustomerDebitNoteItemsRelationManager.php:136` with the same closure
  pattern Phase B applies to `CustomerInvoiceItemsRelationManager`
  (see Phase B "Items Relation Manager" section).
- "Import from Invoice" action overrides `vat_rate_id` to the 0% rate
  when non-registered, regardless of parent invoice's stored rate. Both
  sites (`:195` credit, `:206` debit) currently set
  `'vat_rate_id' => $invoiceItem->vat_rate_id`; gate with the same
  tenant-non-registered check and substitute the resolved zero-rate id
  when the gate is true.
- `afterStateUpdated` line-copy (`:72` credit, `:77` debit) — apply the
  same gate; when non-registered, set `vat_rate_id` to the resolved
  zero-rate id instead of `$invoiceItem->vat_rate_id`.

## Confirmation Modals

Both `ViewCustomerCreditNote::getHeaderActions()` and
`ViewCustomerDebitNote::getHeaderActions()` today use bare
`Action::make('confirm')->requiresConfirmation()` (verified —
`ViewCustomerCreditNote.php:25-35`, `ViewCustomerDebitNote.php:25-35`).

Replace with a schema-based action:
- `->schema(fn ($record): array => $this->buildConfirmationSchema($record))`
- `->action(function (array $data, $record): void { app(...Service::class)->confirmWithScenario($record, $data['goods_services_pick'] ?? null); … })`
- Schema shows:
  - Inherited scenario badge (use `$scenarioColor` map from Phase B).
  - When `vat_scenario_sub_code` starts with `'art_'`: resolved article
    label via `VatLegalReference::resolve()`.
  - Reverse charge indicator when `$note->is_reverse_charge` is true.
  - Financial preview: subtotal / VAT / total, with VAT hidden when
    scenario is zero-rated.
- **Goods/services radio visibility**:
  - Credit note: NEVER shown — parent is mandatory; scenario is always
    inherited; sub_code is whatever the parent stored.
  - Debit note: shown only when `customerInvoice === null` AND the
    `determine()` result is in `[EuB2bReverseCharge, NonEuExport]` AND
    `classifyItems($record) === 'mixed'`.

## PDF Templates

Both `pdf/customer-credit-note.blade.php` and
`pdf/customer-debit-note.blade.php`:

- Copy `pdf/customer-invoice.blade.php` structure (from Phase B — with
  the general legal-notice block and gated VAT totals row).
- Heading: "CREDIT NOTE" / "DEBIT NOTE" (translatable key).
- New row after document number: "Reference invoice: #{parent_number} /
  {parent_date}" when parent exists.
- Same `$legalReference` resolution at Print-action level; same blade
  block keyed on `@isset($legalReference)`.

Print action on both view pages follows the Phase B pattern exactly.

## Tests

### Shared (Credit Note + Debit Note) — Unit
- `confirmWithScenario()` inherits `vat_scenario`, `vat_scenario_sub_code`,
  `is_reverse_charge` from parent.
- When parent has `DomesticExempt` + `art_42`, the note inherits the
  same sub_code (no user re-pick required).
- Inherited scenario with `requiresVatRateChange()` applies 0% to items.
- `EuOssService::accumulate()` is **not** called (mock/spy).
- Non-registered tenant short-circuits to Exempt regardless of parent.

### OSS adjustment — Unit / Feature
- Credit note against a parent with `vat_scenario = EuB2cOverThreshold`
  calls `EuOssService::adjust($parent, $negativeDelta)`; ledger row for
  `$parent->issued_at->year` decreases by the note's EUR-converted total.
- Credit note against a parent with any other scenario does NOT call
  `adjust()`.
- Debit note against an `EuB2cOverThreshold` parent calls
  `EuOssService::adjust($parent, $positiveDelta)`; ledger row for
  `$parent->issued_at->year` increases by the note's EUR-converted total.
- **Cross-year regression**: parent dated `2025-12-20` + credit note
  issued `2026-01-10` books the reversal under year `2025`, not `2026`.
- Standalone debit note (no parent) does NOT accumulate OSS even when
  `determine()` routes to `EuB2cOverThreshold` — documented deferral.

### Credit note only — Unit
- `confirmWithScenario()` throws `DomainException` if the record
  somehow has `customer_invoice_id = null` (defensive guard for a state
  the schema forbids). The test forces the null via `::unguarded()` or
  direct DB update to exercise the guard.

### Debit note only — Unit
- Standalone debit note (no parent) falls back to
  `VatScenario::determine()`.
- Standalone debit note with mixed items raises `DomainException`
  unless `$subCodeHint` is given.
- Standalone debit note with all-goods items auto-infers `'goods'`.
- Standalone debit note with all-services items auto-infers `'services'`.

### Feature — Filament
- Modal renders inherited scenario badge (both note types).
- Modal renders article label when inherited sub_code is `art_XX`
  (both note types).
- `pricing_mode` hidden on form when tenant non-registered (both note
  types).
- Items RM `vat_rate_id` restricted to 0% when non-registered (both).
- "Import from Invoice" header action overrides rate to 0% when
  non-registered even if parent item carried a non-zero rate (both
  note types).
- Per-line `customer_invoice_item_id` Select's `afterStateUpdated`
  also overrides `vat_rate_id` to 0% when non-registered (both note
  types — second override site in the same RM).
- Credit-note confirm modal NEVER shows the goods/services radio
  (parent mandatory).
- Debit-note confirm modal shows goods/services radio only when
  standalone AND determine() routes to reverse-charge/non-EU-export
  AND items classify as mixed.

### Feature — PDF
- Credit/Debit note PDF renders correct document title.
- Parent invoice reference line appears when parent exists; absent for
  standalone debit notes.
- Legal notice block renders when inherited scenario is zero-rated;
  absent for Domestic.
- VAT row gated correctly.

## Verification

1. `./vendor/bin/sail artisan test --parallel --compact --filter="CreditNote|DebitNote"`
   — new tests green.
2. `./vendor/bin/sail artisan test --parallel --compact` — full suite
   (Phases A + B + C combined) still green.
3. Browser smoke:
   - Confirm a parent invoice as `DomesticExempt` + `art_42` → create
     credit note against it → confirm → PDF renders Art. 42 legal
     notice, no VAT row, references parent invoice number.
   - Non-registered tenant → create credit note → confirm → scenario
     persisted as `Exempt` regardless of parent invoice scenario.
   - Standalone debit note with mixed items → confirm → modal shows
     goods/services radio → pick "goods" → PDF renders Art. 138
     legal notice.
   - (Credit note has no standalone path — UI never offers the option
     because the create form requires a parent invoice.)
4. `vendor/bin/pint --dirty --format agent` — clean.
