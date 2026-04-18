# Plan: Pre-Launch Polish

> **Task:** `tasks/vat-vies/pre-launch.md`
> **Review:** `review.md` (F-009, F-012, F-014, F-015, F-016, F-022, F-032)
> **Status:** ✅ SHIPPED (2026-04-18)

---

## Prerequisites

- [x] All feature tasks merged: hotfix, legal-references, pdf-rewrite, domestic-exempt, blocks, invoice-credit-debit, blocks-credit-debit
- [x] Refactor plans merged: tenant-plan, partner-plan, invoice-plan
- [x] Test suite fully green

---

## Step 1 — GDPR / DSAR (F-012)

### `spec.md` — new section "Privacy & Retention"

Add after Shared Design Principles:

```markdown
## Privacy, Retention & Integrity

- **Lawful basis:** GDPR Art. 6(1)(c) — legal obligation. VAT numbers of sole proprietors are personal data under GDPR Art. 4(1).
- **Retention:** 10 years from the end of the fiscal year (BG ЗСч чл. 12 + ЗДДС чл. 121). Other MS retention windows per-country configurable.
- **Erasure:** restricted during retention window. Data subject access (Art. 15) and rectification (Art. 16) are supported; erasure (Art. 17) is restricted.
- **Controller:** the tenant (customer of this SaaS). The SaaS operator is the processor. DPA should cover this.
- **Integrity:** confirmed documents are pinned via `document_hash` (SHA-256 of canonical serialization). Any update attempt throws `RuntimeException` (per `hotfix.md`). Annual integrity check via `hmo:integrity-check`.
- **VIES consultation numbers** stored on confirmed invoices for audit reconstruction (`customer_invoices.vies_request_id`).
```

### Partner DSAR action

**File:** `app/Filament/Resources/Partners/Pages/ViewPartner.php`

```php
use Filament\Actions\Action;

Action::make('data_subject_request')
    ->label('Data subject request (GDPR)')
    ->icon('heroicon-o-shield-check')
    ->requiresConfirmation()
    ->modalDescription('Log a GDPR data-subject request (access, rectification, or erasure) from this partner. Access / rectification requests are fulfilled inline; erasure requests require review (tax-retention restrictions may apply).')
    ->form([
        Select::make('request_type')
            ->options([
                'access' => 'Access (Art. 15)',
                'rectification' => 'Rectification (Art. 16)',
                'erasure' => 'Erasure (Art. 17)',
            ])
            ->required(),
        Textarea::make('notes')
            ->label('Details / request text')
            ->required(),
    ])
    ->action(function (array $data, Partner $record): void {
        activity()
            ->performedOn($record)
            ->causedBy(auth()->user())
            ->withProperties([
                'type' => 'gdpr_data_subject_request',
                'request_type' => $data['request_type'],
                'notes' => $data['notes'],
                'received_at' => now()->toIso8601String(),
            ])
            ->log('GDPR data subject request received');

        if ($data['request_type'] === 'access') {
            // Export partner data as JSON for download
            // Use Laravel's download response; omit invoice content (tenant owns that)
        }

        Notification::make()
            ->title('DSAR logged')
            ->body('Request recorded. Respond within 30 days per GDPR Art. 12(3).')
            ->send();
    });
```

**Tests:** DSAR logged → activity log entry created; response deadline notification surfaced.

---

## Step 2 — OSS threshold warning (F-014)

### Dashboard widget

**File:** `app/Filament/Widgets/OssThresholdWidget.php`

```php
class OssThresholdWidget extends Widget
{
    protected static string $view = 'filament.widgets.oss-threshold';

    public function getViewData(): array
    {
        $year = now()->year;
        $threshold = 10_000;  // Art. 59c
        $current = (float) EuOssAccumulation::forYear($year)->value('amount_eur') ?? 0;
        $pct = min(100, ($current / $threshold) * 100);

        return [
            'current' => $current,
            'threshold' => $threshold,
            'percent' => round($pct),
            'colour' => $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'success'),
        ];
    }
}
```

Simple Blade view:

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">OSS Threshold ({{ now()->year }})</x-slot>
        <div class="text-2xl font-bold">€{{ number_format($current, 2) }} / €{{ number_format($threshold, 0) }}</div>
        <div class="mt-2">
            <div class="w-full h-2 bg-gray-200 rounded">
                <div class="h-2 rounded bg-{{ $colour === 'danger' ? 'red' : ($colour === 'warning' ? 'yellow' : 'green') }}-500" style="width: {{ $percent }}%"></div>
            </div>
        </div>
        @if($percent >= 80)
            <p class="mt-2 text-sm text-{{ $colour === 'danger' ? 'red' : 'yellow' }}-600">
                {{ $percent >= 100 ? 'Threshold exceeded — OSS destination-country VAT now applies.' : 'Approaching threshold — review OSS readiness.' }}
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
```

### Invoice form soft warning

**File:** `app/Filament/Resources/CustomerInvoices/Schemas/CustomerInvoiceForm.php`

```php
Placeholder::make('oss_threshold_warning')
    ->content(function (Get $get) {
        $partner = Partner::find($get('partner_id'));
        if (!$partner || $partner->country_code === tenancy()->tenant->country_code) {
            return null;  // domestic — no OSS concern
        }
        if (!\App\Support\EuCountries::isEuCountry($partner->country_code)) {
            return null;
        }
        if ($partner->vat_status === VatStatus::Confirmed) {
            return null;  // reverse-charge — no OSS accumulation
        }

        $draftTotal = /* compute current draft total in EUR */;
        $accumulator = (float) EuOssAccumulation::forYear(now()->year)->value('amount_eur') ?? 0;

        if ($accumulator + $draftTotal > 10_000 && $accumulator <= 10_000) {
            return '⚠ This invoice would push your OSS accumulator past €10 000. Destination-country VAT will apply from here on.';
        }
        return null;
    })
    ->visible(fn ($state) => $state !== null)
    ->columnSpanFull(),
```

### First-crossing notification

Emit a one-time persistent notification when the accumulator first crosses the threshold. Store a flag in `EuOssAccumulation` row (new column `threshold_crossed_notified_at`) so we only notify once per year.

---

## Step 3 — Foreign Exchange Service (F-015)

**File:** `app/Services/ForeignExchangeService.php`

```php
class ForeignExchangeService
{
    public function __construct(
        private BnbRateProvider $bnb,
        private EcbRateProvider $ecb,
    ) {}

    /**
     * Convert amount from $from to $to at the rate published on $at.
     * Default provider = BNB reference; fallback ECB.
     */
    public function convert(string $from, string $to, float $amount, \DateTimeInterface $at): float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return $amount;
        }

        $rate = $this->getRate($from, $to, $at);
        return round($amount * $rate, 5);  // 5 decimals for rate, 2 decimals at display
    }

    public function getRate(string $from, string $to, \DateTimeInterface $at): float
    {
        try {
            return $this->bnb->rate($from, $to, $at);
        } catch (\Exception) {
            return $this->ecb->rate($from, $to, $at);
        }
    }

    public function source(): string
    {
        return 'bnb';  // tracks which source was used for the last call
    }
}
```

`BnbRateProvider` and `EcbRateProvider` — stubs initially; fetch from BNB XML feed / ECB daily feed with daily caching. Full implementation can lag Step 3 landing if providers are seeded manually.

### Migration — audit the FX source

```php
Schema::table('customer_invoices', function (Blueprint $table) {
    $table->string('exchange_rate_source', 10)->nullable()->after('exchange_rate');
});
```

Store `'bnb'` / `'ecb'` / `'fixed_eur'` on every confirmed invoice.

### Refactor call sites

- `CustomerInvoiceService` — when writing `exchange_rate`, call `ForeignExchangeService::convert()` and record the source
- `EuOssService::accumulate()` + `adjust()` — same
- `CreditNoteService::convertTotalToEur()` + DebitNoteService — same

### spec.md entry

```markdown
## Currency & FX

- Invoices may be issued in any currency; tax base is determined in the tenant's accounting currency.
- Exchange rate source: BNB reference rate on the chargeable-event date (default); ECB reference rate as fallback.
- Rates read via `ForeignExchangeService`; source stored on each confirmed invoice (`exchange_rate_source`).
- Rounding: 5 decimals for the rate; 2 decimals half-up for the tax base.
- Post-euro (2026-01-01): BG amounts denominated in EUR.
```

---

## Step 4 — Retention + integrity (F-016)

### `document_hash` column

Migrations on all three document tables:

```php
Schema::table('customer_invoices', function (Blueprint $table) {
    $table->string('document_hash', 64)->nullable()->after('status');
});
```

Service — compute hash at confirmation:

```php
protected function pinHash(CustomerInvoice $invoice): void
{
    $canonical = json_encode([
        'invoice_number' => $invoice->invoice_number,
        'issued_at' => $invoice->issued_at?->toIso8601String(),
        'supplied_at' => $invoice->supplied_at?->toIso8601String(),
        'partner_id' => $invoice->partner_id,
        'vat_scenario' => $invoice->vat_scenario?->value,
        'vat_scenario_sub_code' => $invoice->vat_scenario_sub_code,
        'is_reverse_charge' => $invoice->is_reverse_charge,
        'subtotal' => (string) $invoice->subtotal,
        'tax_amount' => (string) $invoice->tax_amount,
        'total' => (string) $invoice->total,
        'currency_code' => $invoice->currency_code,
        'items' => $invoice->items->map(fn ($i) => [
            'product_variant_id' => $i->product_variant_id,
            'quantity' => (string) $i->quantity,
            'unit_price' => (string) $i->unit_price,
            'vat_rate_id' => $i->vat_rate_id,
            'line_total' => (string) $i->line_total,
        ])->toArray(),
    ], JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS);

    $invoice->document_hash = hash('sha256', $canonical);
    $invoice->saveQuietly();  // bypass updating-guard — we're in controlled code
}
```

Call from `confirmWithScenario()` just before transaction commit. Same for credit / debit note services.

### `hmo:integrity-check` artisan command

```php
class IntegrityCheckCommand extends Command
{
    protected $signature = 'hmo:integrity-check {--type=invoice : invoice|credit-note|debit-note|all}';
    protected $description = 'Verify document_hash across confirmed documents.';

    public function handle(): int
    {
        $mismatches = [];
        CustomerInvoice::where('status', DocumentStatus::Confirmed)
            ->chunk(500, function ($chunk) use (&$mismatches) {
                foreach ($chunk as $invoice) {
                    $recomputed = $this->recompute($invoice);
                    if ($recomputed !== $invoice->document_hash) {
                        $mismatches[] = $invoice->invoice_number;
                    }
                }
            });

        if ($mismatches) {
            $this->error('Integrity mismatches detected:');
            $this->table(['Invoice #'], array_map(fn ($n) => [$n], $mismatches));
            return self::FAILURE;
        }

        $this->info('All documents pass integrity check.');
        return self::SUCCESS;
    }

    // recompute() mirrors pinHash() logic
}
```

### Tenant-delete gate

Coordinate with the existing tenant lifecycle task (per CLAUDE.md memory `project_tenant_lifecycle`). When hard-delete is requested:

- Check: any confirmed document exists AND the document's fiscal year + 10 < now() is false → block
- Offer: "Export archive and delete" flow (zips all confirmed PDFs + their hashes + metadata, sends to tenant admin, then proceeds)

---

## Step 5 — OSS service coverage (F-022)

Add regression test:

```php
it('accumulates OSS for service invoices, not just goods', function () {
    $servicePartner = Partner::factory()->create(['country_code' => 'DE', 'vat_status' => VatStatus::NotRegistered]);
    $invoice = CustomerInvoice::factory()->draft()->forPartner($servicePartner)->withServiceItems(3)->create();

    app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

    expect((float) EuOssAccumulation::forYear(now()->year)->value('amount_eur'))->toBeGreaterThan(0);
});
```

Document in `spec.md` the special-rule services that SHOULD NOT route to EuB2c* (Art. 47 immovable, Art. 53/54 events, Art. 48-52 transport, Art. 55 restaurant). Add a backlog item for product-category VAT place-of-supply tagging.

---

## Step 6 — Invoice numbering audit (F-032)

Read existing numbering service (likely `app/Services/InvoiceNumberingService.php` or inline in `CustomerInvoiceService`). Verify:

1. Number allocated at confirmation, not draft creation
2. Allocation inside transaction with row lock on a `tenant_invoice_sequences` table (or equivalent)
3. Format: 10-digit, zero-padded, Arabic numerals only
4. Per-tenant sequence (not global)

Regression test:

```php
it('keeps invoice numbering contiguous across draft deletions', function () {
    $i1 = CustomerInvoice::factory()->draft()->create();
    $i2 = CustomerInvoice::factory()->draft()->create();

    $i1->delete();  // Draft delete — allowed

    $i3 = CustomerInvoice::factory()->draft()->create();
    app(CustomerInvoiceService::class)->confirmWithScenario($i2);
    app(CustomerInvoiceService::class)->confirmWithScenario($i3);

    // Numbers should be N and N+1, not N+1 and N+2 or any gap
    expect((int) $i3->invoice_number - (int) $i2->invoice_number)->toBe(1);
});
```

If the audit surfaces issues, file them as their own refactor entry in `invoice.md`.

---

## Tests

One file per step, named after the feature. Consolidate smaller ones into `tests/Feature/PreLaunchTest.php`.

---

## Gotchas / load-bearing details

1. **Don't hash `updated_at`** — it changes every save. Canonical serialization must be **fields only**.
2. **`saveQuietly()`** bypasses model events — needed to skip the immutability guard when writing `document_hash` inside the same confirmation transaction. Safe because the hash is written once.
3. **Integrity-check runs inside tenant context** — same pattern as `hmo:vat-remediate-country-code` in hotfix.
4. **BNB / ECB rate providers** — fetching rates from external APIs needs retry + cache. Ship with seeded rates for dev/test; wire providers in production.
5. **OSS threshold banner** runs a query on every form render — cache the accumulator value for 60s per tenant.
6. **Tenant hard-delete gate** interacts with the existing tenant lifecycle code. Coordinate; don't reinvent.

---

## Exit Criteria

- [ ] All pre-launch tests green
- [ ] Full suite green
- [ ] Manual: GDPR DSAR action works end-to-end
- [ ] Manual: OSS dashboard widget visible; invoice form banner appears in threshold-crossing draft
- [ ] Manual: cross-currency invoice uses correct BNB rate + `exchange_rate_source = 'bnb'`
- [ ] Manual: `hmo:integrity-check` passes for all documents
- [ ] Manual: attempt to hard-delete a tenant with confirmed documents → blocked; archive-flow offered
- [ ] Manual: invoice number sequence verified contiguous in a scratch tenant
- [ ] Pint clean
- [ ] `pre-launch.md` checklist ticked
- [ ] **Final reviewer sign-off** before first real tenant onboards
