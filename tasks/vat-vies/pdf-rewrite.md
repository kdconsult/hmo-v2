# Task: Per-Country PDF Rewrite — Invoice + Credit Note + Debit Note

> **Spec:** `tasks/vat-vies/spec.md`
> **Plan:** `tasks/vat-vies/pdf-rewrite-plan.md`
> **Combined push with:** `domestic-exempt.md` — ship together in one branch / PR. This file owns the PDF side (migrations, templates, resolver, service guards). `domestic-exempt.md` owns the scenario semantics (enum case, form UX, service routing). The shared column migration + enum case are emitted by **this** plan because the templates cannot render without them.
> **Status:** ✅ SHIPPED (2026-04-18)
> **Depends on:** `hotfix.md` ✅ shipped, `legal-references.md` ✅ shipped
> **Unblocks:** `blocks.md`, `invoice-credit-debit.md` (credit/debit scenario inheritance reuses the templates + resolver established here), `blocks-credit-debit.md`

---

## Why this task exists

- **F-001** (BLOCKER) — current PDF has no legal-reference line for zero-rate / exempt / reverse-charge / export scenarios.
- **F-002** (BLOCKER) — PDF misses Art. 226 Directive 2006/112/EC content: date of chargeable event, per-rate VAT breakdown, localized wording, full supplier legal address.
- **F-004** — stale "Art. 96 ЗДДС" citation in older blocks docs (Art. 96 is the registration-threshold rule, never belongs on an invoice — the correct exempt cite is чл. 113, ал. 9 ЗДДС).
- **F-013** — VIES `requestIdentifier` is stored but never rendered on the PDF.
- **F-023** — a tenant without its own VAT number can currently reach an `EuB2bReverseCharge` confirmation path that produces a null `vies_request_id` — audit trail broken.
- **F-028** — 5-day issuance rule (чл. 113, ал. 4 ЗДДС) not enforced; user can confirm an invoice for a supply six weeks ago with no warning.
- **F-029** (BLOCKER for BG) — heading hard-coded "INVOICE"; чл. 114, ал. 1, т. 1 ЗДДС requires "**ФАКТУРА**" (and "ИЗВЕСТИЕ ЗА КРЕДИТ" / "ИЗВЕСТИЕ ЗА ДЕБИТ" for notes).

Separately: HMO targets the whole EU, so the PDF system must be **per-country from day one**. Phase 1 ships **two** templates per document: `default` (tenant-locale, Art. 226-compliant catch-all) and `bg` (Bulgarian statutory, fixed-locale Bulgarian wording). Additional countries (DE, FR, NL, …) are added as each member state onboards.

---

## Scope

### Document types
- **Customer Invoice** — rewrite existing flat template into new per-country structure
- **Customer Credit Note** — new template (none exists today; no print action today)
- **Customer Debit Note** — new template (none exists today; no print action today)

### Template system
- `resources/views/pdf/{doc-type}/{country}.blade.php` — one template per doc-type per country
- `resources/views/pdf/components/` — shared Blade partials reused across doc-types and countries (header, parties, vat-treatment, items table, totals, footer, styles)
- `App\Services\PdfTemplateResolver` — resolves `pdf.{doc-type}.{country}` by tenant `country_code` with fallback to `default`; also returns the locale the template should render in
- Ship with `default` + `bg` only; DE / FR / NL etc. plug in later without refactor

### Locale strategy
- **`bg` template:** forces `bg` locale regardless of tenant UI locale — statutory (НАП expects Bulgarian on BG-issued documents)
- **`default` template:** uses tenant UI locale (`tenants.locale`) with fallback to `config('app.fallback_locale', 'en')`
- Translation files under `lang/{bg,en}/invoice-pdf.php`. Additional locales added per-country as needed

### Data model
- `customer_invoices.supplied_at` — nullable date (date of chargeable event; Art. 63–66 Directive; чл. 25 ЗДДС). Falls back to `issued_at` when null
- `customer_invoices.vat_scenario_sub_code` — nullable string with backfill. **Owned by `domestic-exempt.md` conceptually; migrated in this push** because the templates read it
- `customer_credit_notes.vat_scenario_sub_code` + `customer_debit_notes.vat_scenario_sub_code` — same column on both note tables so notes can render the same legal reference as the parent invoice

### Service guards
- `CustomerInvoiceService::confirmWithScenario()` — refuse to confirm `EuB2bReverseCharge` when tenant's `vat_number` is null (F-023). Surfaced as a Filament notification
- Same service — non-blocking warning when `issued_at - supplied_at > 5 days` (F-028)

### UI
- Invoice form: add `supplied_at` DatePicker (defaults to `issued_at` if blank)
- Invoice view page: existing "Print Invoice" action re-wires through the resolver
- Credit Note view page: new "Print Credit Note" action via resolver
- Debit Note view page: new "Print Debit Note" action via resolver

---

## Non-scope

- **DE / FR / NL / other country templates** — added as tenants onboard; not in v1
- **Other outgoing PDFs** (Quotation, DeliveryNote, AdvancePayment, SalesReturn) — user directive: "as we need"; add later
- **Credit/Debit note scenario inheritance logic** — `invoice-credit-debit.md` owns this. This push creates the template shells + the column and ensures data flows through, but does not implement scenario-determination for notes
- **DomesticExempt form UX** — `domestic-exempt.md` owns this (toggle, sub-code picker, items RM restriction)
- **Non-VAT-registered tenant blocks** — `blocks.md`
- **F-006 OSS year fix** — the `supplied_at` column this push adds is a prerequisite; the behavioural change in `EuOssService::accumulate()` is owned by `pre-launch.md`
- **F-007 Non-EU B2B services classification** — separate scenario-enum task
- **E-invoicing XML (ViDA / PEPPOL BIS)** — backlog
- **Proforma / fiscal receipt PDFs** — unchanged
- **DomPDF → other renderer migration** — stay on DomPDF + DejaVu Sans

---

## Known Changes

### File layout

```
resources/views/pdf/
  components/
    _styles.blade.php
    _header.blade.php
    _parties.blade.php
    _vat-treatment.blade.php
    _items-table.blade.php
    _totals.blade.php
    _footer.blade.php
  customer-invoice/
    default.blade.php
    bg.blade.php
  customer-credit-note/
    default.blade.php
    bg.blade.php
  customer-debit-note/
    default.blade.php
    bg.blade.php
lang/
  bg/invoice-pdf.php
  en/invoice-pdf.php
```

The legacy flat `resources/views/pdf/customer-invoice.blade.php` is deleted once the new templates ship.

### Migrations

- `customer_invoices.supplied_at` — nullable date, positioned after `issued_at`
- `customer_invoices.vat_scenario_sub_code` — nullable string after `vat_scenario`; backfilled per scenario (see plan Step 2)
- `customer_credit_notes.vat_scenario_sub_code` — same column, same backfill rules
- `customer_debit_notes.vat_scenario_sub_code` — same

### New service

`App\Services\PdfTemplateResolver` — `resolve(string $docType, ?string $countryCode = null): string` + `localeFor(string $docType, ?string $countryCode = null): string`. Used by every PDF print action.

### Translation files

`lang/bg/invoice-pdf.php` + `lang/en/invoice-pdf.php` — keys for headings, labels, statutory wording, meta rows, totals, footer. Full key list in plan Step 4.

### Enum

`VatScenario::DomesticExempt` case added (shared with `domestic-exempt.md`; emitted here so templates can resolve).

### Service edits

`CustomerInvoiceService::confirmWithScenario()` — two new inline guards (F-023, F-028). No signature change in this task (`domestic-exempt.md` extends the signature separately).

### Form edits

`CustomerInvoiceForm` — `supplied_at` DatePicker after `issued_at`. Late-issuance warning fires at confirmation, not form-time.

### Print-action call sites

- `ViewCustomerInvoice::print_invoice` — routes through resolver
- `ViewCustomerCreditNote` — new action
- `ViewCustomerDebitNote` — new action

---

## Tests Required

### Resolver
- [ ] Unit: BG tenant → resolver returns `pdf.customer-invoice.bg`
- [ ] Unit: DE tenant (no DE template) → resolver returns `pdf.customer-invoice.default`
- [ ] Unit: resolver returns `bg` locale for BG tenant on BG template
- [ ] Unit: resolver returns tenant UI locale for default template

### Invoice PDF rendering (all scenarios)
- [ ] Feature: BG tenant → BG template renders "ФАКТУРА" heading
- [ ] Feature: DE tenant on default template → heading localized via `tenants.locale`
- [ ] Feature: Domestic invoice → per-rate VAT breakdown; one net/VAT row per distinct rate
- [ ] Feature: Reverse-charge invoice → "Обратно начисляване" (BG) / localized equivalent (default) rendered
- [ ] Feature: Reverse-charge invoice → VIES consultation number + timestamp rendered
- [ ] Feature: Exempt invoice → "чл. 113, ал. 9 ЗДДС" legal notice rendered; no VAT breakdown
- [ ] Feature: DomesticExempt → `чл. {39..49} ЗДДС` rendered from `vat_scenario_sub_code`
- [ ] Feature: Non-EU export (goods) → "Art. 146 Directive 2006/112/EC" rendered
- [ ] Feature: Non-EU export (services) → "Art. 44 Directive 2006/112/EC (outside scope of EU VAT)" rendered
- [ ] Feature: EuB2cOverThreshold → destination country + rate rendered
- [ ] Feature: `supplied_at` distinct from `issued_at` → both dates rendered
- [ ] Feature: `supplied_at` null or equal to `issued_at` → only "Date of issue" rendered
- [ ] Feature: Supplier legal address rendered in "From" block
- [ ] Feature: Customer legal address (billing / default `partner_addresses` row) rendered in "To" block

### Credit-note + debit-note PDF rendering
- [ ] Feature: Credit Note BG template → "КРЕДИТНО ИЗВЕСТИЕ" heading
- [ ] Feature: Debit Note BG template → "ДЕБИТНО ИЗВЕСТИЕ" heading
- [ ] Feature: Credit Note carrying parent `vat_scenario_sub_code` renders matching legal reference
- [ ] Feature: Debit Note carrying parent `vat_scenario_sub_code` renders matching legal reference
- [ ] Feature: Both note types render VIES consultation when parent was reverse-charge

### Service guards
- [ ] Feature: Confirming `EuB2bReverseCharge` with tenant `vat_number = null` → user-surfaced error; no state change
- [ ] Feature: `issued_at - supplied_at > 5 days` → Filament warning notification; invoice still confirms
- [ ] Feature: Confirmed `EuB2bReverseCharge` invoice has non-null `vies_request_id` (regression lock for F-023)

### Form
- [ ] Feature: `supplied_at` field visible; defaults to `issued_at`; manually overridable; leaving blank stores null

### Cyrillic / DomPDF smoke
- [ ] Feature: DomPDF render of BG invoice produces non-trivial binary with no missing-glyph markers

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [x] Investigation complete (verify: DomPDF Cyrillic font coverage; partner_addresses shape; credit/debit-note model print integration points)
- [x] Plan written (`pdf-rewrite-plan.md`)
- [x] `supplied_at` migration + model cast
- [x] `vat_scenario_sub_code` migration + backfill (invoice + credit-note + debit-note)
- [x] `VatScenario::DomesticExempt` enum case
- [x] Translation files created (bg, en)
- [x] `PdfTemplateResolver` service + unit tests
- [x] Shared Blade components (`pdf/components/`)
- [x] Invoice templates (default + bg)
- [x] Credit-Note templates (default + bg)
- [x] Debit-Note templates (default + bg)
- [x] Service guards (F-023, F-028) with tests
- [x] Form updated (`supplied_at` DatePicker)
- [x] Print actions re-wired / added (invoice + credit note + debit note)
- [x] Legacy flat `pdf.customer-invoice.blade.php` deleted
- [x] Automated tests pass (631 passing, 3 todos)
- [ ] Browser-tested: BG tenant — each of the 3 doc types × each scenario
- [ ] Browser-tested: non-BG tenant (simulate DE) renders default template in tenant locale
- [x] Pint clean
- [x] Final test run green
