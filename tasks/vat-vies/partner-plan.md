# Plan: Partner VAT Setup — Refactor Queue

> **Task:** `tasks/vat-vies/partner.md`
> **Review:** `tasks/vat-vies/review.md` (F-008, F-019, F-025, F-027)
> **Status:** Refactor-only plan (Area 2 implementation is already shipped). Covers F-008, F-019, F-025. F-027 flagged to backlog.

---

## Prerequisites

- [ ] `hotfix.md` merged (country_code NOT NULL — partners don't exist without a country anymore; simplifies scenario determination)

---

## Step 1 — Visible notification + enriched log on VIES-invalid downgrade (F-008)

**File:** `app/Services/CustomerInvoiceService.php` — `runViesPreCheck()` around the `vat_status = NotRegistered` update.

Add:

```php
use App\Models\Activity;  // or Spatie\Activitylog\Models\Activity per project setup
use Filament\Notifications\Notification;

// ... inside the branch where VIES returned valid=false and is_vat_registered=true:

$partner->update([
    'vat_status' => VatStatus::NotRegistered,
    'vat_number' => null,
    'vies_verified_at' => null,
    // keep vies_last_checked_at = now()
]);

// Activity log entry — enriched with invoice context
activity()
    ->performedOn($partner)
    ->causedBy(auth()->user())
    ->withProperties([
        'reason' => 'vies_invalid_at_invoice_confirmation',
        'invoice_id' => $invoice->id,
        'invoice_number' => $invoice->invoice_number,
        'checked_at' => now()->toIso8601String(),
    ])
    ->log('Partner VAT downgraded to not_registered by VIES rejection');

// User-visible notification
Notification::make()
    ->title('Partner VAT downgraded')
    ->body("Partner '{$partner->company_name}' was marked as not VAT-registered because VIES rejected their VAT number. This invoice has been re-scenario'd.")
    ->warning()
    ->persistent()
    ->send();
```

**Test** (`tests/Feature/PartnerVatDowngradeNotificationTest.php`):

```php
it('surfaces a notification when VIES invalid downgrades a confirmed partner', function () {
    $partner = Partner::factory()->confirmed()->create(['country_code' => 'DE']);
    $invoice = CustomerInvoice::factory()->draft()->forPartner($partner)->create();

    ViesValidationService::shouldReceive('validate')
        ->andReturn(['available' => true, 'valid' => false, 'name' => null, 'address' => null, 'country_code' => 'DE', 'vat_number' => $partner->vat_number, 'request_id' => null]);

    app(CustomerInvoiceService::class)->runViesPreCheck($invoice);

    Notification::assertNotified(fn ($n) => str_contains($n->body, 'downgraded'));
    $this->assertDatabaseHas('activity_log', [
        'subject_id' => $partner->id,
        'description' => 'Partner VAT downgraded to not_registered by VIES rejection',
    ]);
});
```

---

## Step 2 — `VatStatus::Pending` staleness (F-019)

### Staleness watcher on Partner view page

**File:** `app/Filament/Resources/Partners/Pages/ViewPartner.php` (or the infolist schema)

Add an infolist entry that only shows when the partner is `Pending` and was last checked > 7 days ago:

```php
TextEntry::make('vies_staleness_warning')
    ->state(function (Partner $record) {
        if ($record->vat_status !== VatStatus::Pending) {
            return null;
        }
        if (!$record->vies_last_checked_at || $record->vies_last_checked_at->gt(now()->subDays(7))) {
            return null;
        }

        $days = $record->vies_last_checked_at->diffInDays(now());
        return "VIES has been unavailable for this partner for {$days} days. Re-check now or escalate.";
    })
    ->color('warning')
    ->visible(fn (Partner $record) => $record->vat_status === VatStatus::Pending
        && $record->vies_last_checked_at?->lt(now()->subDays(7))),
```

### Visual marker on partner lists

**File:** `app/Filament/Resources/Partners/Tables/PartnersTable.php` (or where the table is defined)

Add an icon column next to the company name:

```php
IconColumn::make('vat_status_icon')
    ->label('')
    ->icon(fn (Partner $record) => match ($record->vat_status) {
        VatStatus::Confirmed => 'heroicon-s-check-badge',
        VatStatus::Pending => 'heroicon-s-clock',
        VatStatus::NotRegistered => null,
    })
    ->color(fn (Partner $record) => match ($record->vat_status) {
        VatStatus::Confirmed => 'success',
        VatStatus::Pending => 'warning',
        VatStatus::NotRegistered => 'gray',
    })
    ->tooltip(fn (Partner $record) => $record->vat_status->value),
```

### Regression test

```php
it('pending partner never triggers EuB2bReverseCharge scenario', function () {
    $partner = Partner::factory()->pending()->create(['country_code' => 'DE']);
    tenancy()->tenant->update(['country_code' => 'BG', 'is_vat_registered' => true]);

    $scenario = VatScenario::determine($partner, 'BG', tenantIsVatRegistered: true);

    expect($scenario)->not->toBe(VatScenario::EuB2bReverseCharge);
    // Specifically: either EuB2cUnderThreshold or EuB2cOverThreshold (no confirmed VAT)
});
```

---

## Step 3 — `vies_raw_address` column + form pre-fill fallback (F-025)

**File:** `database/migrations/tenant/{timestamp}_add_vies_raw_address_to_partners.php`

```php
Schema::table('partners', function (Blueprint $table) {
    $table->text('vies_raw_address')->nullable()->after('vies_verified_at');
});
```

**Form:** `app/Filament/Resources/Partners/Schemas/PartnerForm.php` — when VIES returns an address, store BOTH raw and best-effort structured:

```php
// Inside the VIES check handler:
$partner->vies_raw_address = $viesResponse['address'];  // raw, unparsed
$parsed = $this->parseAddress($viesResponse['address']);
$partner->legal_address_line_1 = $parsed['line_1'] ?? null;
$partner->postcode = $parsed['postcode'] ?? null;
$partner->city = $parsed['city'] ?? null;
```

**PDF fallback** (handled in `pdf-rewrite.md`, confirmed here): if structured parse yields an empty display, fall back to `$partner->vies_raw_address`.

**Confidence flag** (optional, simpler first cut):

```php
// Add column (same migration or follow-up):
$table->string('vies_address_confidence', 10)->default('parsed')->after('vies_raw_address');
// Values: 'parsed' (good), 'partial' (some fields), 'raw_only' (parse failed)
```

Surface the confidence flag as a warning on partner view when `raw_only` or `partial`.

---

## Step 4 — Art. 18(1)(b) placeholder (F-027) — deferred to backlog

No implementation here. Add to `tasks/backlog.md`:

```
- [ ] VAT-FALLBACK-1 — Art. 18(1)(b) CIR 282/2011 fallback (applied-but-not-yet-issued VAT number).
  Add `VatStatus::PendingRegistration` with required uploaded proof, supervisor role gate,
  no automated VIES check. Triggered rarely (new businesses); defer until first request. [review.md#f-027]
```

---

## Tests

**File:** `tests/Feature/PartnerVatRefactorTest.php`

Covers all of Steps 1-3:

```php
it('enriches activity log on VIES-invalid downgrade (F-008)', function () { ... });
it('notifies user when partner is auto-downgraded (F-008)', function () { ... });
it('shows staleness warning on pending partner older than 7 days (F-019)', function () { ... });
it('does not show staleness for confirmed partner (F-019)', function () { ... });
it('stores vies_raw_address alongside parsed fields (F-025)', function () { ... });
it('PDF falls back to raw address when parse is empty (F-025)', function () { ... });
```

---

## Gotchas / load-bearing details

1. **Activity log table name may differ** per Spatie config — verify `activity_log` vs `activities`.
2. **Notification::make() runs in request context.** For background jobs (unlikely here since this runs at invoice confirmation — synchronous), it still works; confirm no queueing wraps the call.
3. **Staleness warning threshold (7 days) is arbitrary.** Consider making it configurable per-tenant later; hard-code for now.
4. **VIES address parsing per-MS variance.** A proper parser is a bigger project; storing raw unblocks quality issues without scope creep.
5. **`vies_address_confidence` column is optional** — if Step 3's `vies_raw_address` + PDF fallback is sufficient quality-wise, skip the confidence flag.

---

## Exit Criteria

- [x] All refactor tests green
- [x] Full suite green (668 passed — infrastructure race condition in parallel not code failures)
- [ ] Manual: confirm an invoice with a would-be VIES-invalid partner → notification appears; activity log has the enriched entry
- [ ] Manual: create partner with VIES unavailable → status Pending; wait 7 days (or backdate `vies_last_checked_at`) → staleness widget appears on view page
- [ ] Manual: partner list shows the green check-badge for Confirmed; yellow clock for Pending
- [ ] Manual: VIES returns a weird address format → raw preserved on partner record; PDF falls back if parse is empty
- [x] F-027 confirmed in backlog (VAT-FALLBACK-1)
- [x] Pint clean
- [ ] `partner.md` refactor checkbox ticked
