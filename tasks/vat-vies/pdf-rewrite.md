# Task: Invoice PDF Rewrite — Art. 226 Compliance

> **Spec:** `tasks/vat-vies/spec.md`
> **Plan:** `tasks/vat-vies/pdf-rewrite-plan.md`
> **Status:** 📋 PLANNED
> **Depends on:** `hotfix.md` landed, `legal-references.md` landed
> **Unblocks:** `domestic-exempt.md`, `invoice-credit-debit.md` (credit/debit PDF templates reuse patterns from here)

---

## Why this task exists

The current invoice PDF fails multiple Art. 226 Directive 2006/112/EC and чл. 114 ЗДДС requirements. Specifically:

- No legal reference line for exempt / reverse-charge / export / EU-destination-VAT scenarios (F-001)
- No date of chargeable event when it differs from issue date (F-002)
- No per-rate VAT breakdown (mixed-rate invoices render a single consolidated VAT line) (F-002)
- Reverse-charge wording is hard-coded English; BG law requires "обратно начисляване" (F-002)
- Document heading is hard-coded "INVOICE"; BG law requires "ФАКТУРА" (F-029)
- No supplier legal address (Art. 226(5)) (F-002)
- VIES `requestIdentifier` stored but never shown on PDF (F-013)
- Reverse-charge confirmation does not block when tenant VAT is null (no `request_id` generated → audit trail broken) (F-023)
- Invoice has no concept of `supplied_at` (date of chargeable event), so the 5-day issuance rule (чл. 113, ал. 4 ЗДДС) cannot be enforced (F-028)

This task addresses all of them in a single coordinated rewrite of the PDF template, the `CustomerInvoice` schema, the `CustomerInvoiceService` confirmation guards, and the translation files.

---

## Scope

- Add `supplied_at` nullable date column on `customer_invoices` (date of chargeable event; distinct from `issued_at`)
- Extend `CompanySettings` or `Tenant` with full legal-address fields (street, city, postcode, country) if not already present
- Extend `Partner` with the same address structure if not already there (for the recipient block)
- Rewrite `resources/views/pdf/customer-invoice.blade.php` with:
  - Localized heading driven by tenant invoicing locale
  - Full "From (supplier)" block with legal address
  - Full "To (customer)" block with legal address
  - Meta box with issue date + chargeable-event date + payment terms
  - Line items with per-rate grouping awareness
  - Totals block grouped by VAT rate
  - Legal reference line resolved from `vat_legal_references` via `VatScenario` + `sub_code`
  - Localized "Reverse charge" wording when applicable
  - VIES consultation number + check timestamp when reverse-charge
- Translation files (`resources/lang/{bg,en,de,fr}/invoice-pdf.php`) for every user-visible label
- `CustomerInvoiceService::confirmWithScenario()` guard: refuse to confirm `EuB2bReverseCharge` when tenant's `vat_number` is null (which implies VIES `request_id` will be null) — surface user error
- `CustomerInvoiceService` guard: warn (not block) when `issued_at - supplied_at > 5 days`
- Form: optional `supplied_at` input on the invoice form with sensible default (issued date)

---

## Non-scope

- Credit / debit note PDF templates (→ `invoice-credit-debit.md`)
- DomesticExempt sub-code UI toggle (→ `domestic-exempt.md`)
- Non-BG country legal references (future per-country seed)
- Fiscal-printer tax-group mapping (→ backlog, F-026)
- E-invoicing XML format (→ backlog, ViDA work)

---

## Known Changes

### Data model — `customer_invoices`

Add:
- `supplied_at` — nullable date; date of chargeable event (Art. 63–66 Directive; чл. 25 ЗДДС). Defaults to `issued_at` at form level if user leaves it blank.

### Data model — `CompanySettings` / `Tenant` / `Partner` legal address

Verify these exist. If not, add:
- `legal_address_line_1`, `legal_address_line_2`, `postcode`, `city`, `country_code` (country_code already handled in hotfix)

If partners / tenants already have an address structure under a different naming scheme, reuse as-is and adapt the PDF template to the existing shape. **Check sibling code first.**

### Service layer

- `CustomerInvoiceService::confirmWithScenario()` — when scenario is `EuB2bReverseCharge`, require `tenancy()->tenant?->vat_number` to be non-null; else throw user-surfaced `DomainException`
- Same for `NonEuExport` if tenant is VAT-registered (optional; per `[review.md#f-023]` recommendation)
- Inside `confirmWithScenario()`, surface a Filament notification warning if `issued_at - (supplied_at ?? issued_at) > 5 days`. Non-blocking.

### PDF template — complete rewrite

- Rendered via existing DomPDF / Barryvdh setup (assume); if not, adapt accordingly.
- One shared Blade layout component to be reused later by credit / debit note templates: extract `resources/views/pdf/partials/` subdirectory.
- Use translation keys (`__('invoice-pdf.heading')`) throughout; default locale = tenant invoicing locale.

### Translations

Create `resources/lang/{bg,en,de,fr}/invoice-pdf.php` with:
- `heading` (Фактура / Invoice / Rechnung / Facture)
- `reverse_charge` (Обратно начисляване / Reverse charge / Steuerschuldnerschaft des Leistungsempfängers / Autoliquidation)
- `date_of_issue`, `date_of_supply`, `due_date`
- `from_supplier`, `to_customer`
- `vat_id`, `eik`, `vat_treatment`, `vies_consultation`
- Labels for per-rate breakdown rows
- Footer retention notice

Minimum ship: BG + EN. DE / FR placeholder with English fallback; flesh out when those tenants appear.

---

## Tests Required

### PDF rendering
- [ ] Feature: BG tenant → heading renders as "ФАКТУРА"
- [ ] Feature: EN locale → heading renders as "INVOICE"
- [ ] Feature: Domestic invoice → full per-rate VAT breakdown rendered; one row per distinct rate
- [ ] Feature: Reverse-charge invoice → "обратно начисляване" rendered (BG locale)
- [ ] Feature: Reverse-charge invoice → VIES consultation number + timestamp rendered
- [ ] Feature: Exempt invoice → "чл. 113, ал. 9 ЗДДС" legal notice rendered, no VAT breakdown
- [ ] Feature: Non-EU export (goods) → "Art. 146 Directive 2006/112/EC" rendered
- [ ] Feature: Non-EU export (services) → "Art. 44 Directive 2006/112/EC (outside scope of EU VAT)" rendered
- [ ] Feature: EuB2cOverThreshold → destination country VAT rate applied; PDF shows destination country in a clear line
- [ ] Feature: `supplied_at` different from `issued_at` → both rendered as distinct rows
- [ ] Feature: `supplied_at` same as `issued_at` → only "Date of issue" rendered
- [ ] Feature: Full supplier legal address rendered in "From" block
- [ ] Feature: Full customer legal address rendered in "To" block

### Service guards
- [ ] Feature: Confirming an EuB2bReverseCharge invoice with tenant `vat_number = null` throws user-surfaced error
- [ ] Feature: Confirming with `issued_at - supplied_at > 5 days` shows a Filament notification warning (non-blocking)
- [ ] Feature: Confirmed `EuB2bReverseCharge` invoice has non-null `vies_request_id` (no null-req-id reverse-charge invoices possible)

### Form
- [ ] Feature: `supplied_at` field visible on the invoice form; defaults to `issued_at`; manually overridable

### Migration
- [ ] Feature: `supplied_at` migration runs cleanly; existing invoices get null (rendered as issued_at in PDF)
- [ ] Feature: If any address fields added, data-fix migration populates existing rows from whatever the current address source is

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [ ] Investigation complete (address fields present / missing; locale mechanism)
- [ ] Plan written (`pdf-rewrite-plan.md`)
- [ ] `supplied_at` migration + model cast
- [ ] Address field migrations (if needed)
- [ ] Translation files created
- [ ] PDF template rewritten + partials extracted
- [ ] Service guards added (tenant VAT null, 5-day warn)
- [ ] Form updated with `supplied_at`
- [ ] Automated tests pass
- [ ] Browser-tested: rendered PDF for each scenario side-by-side with a BG sample invoice
- [ ] Pint clean
- [ ] Final test run green
