# Task: Pre-Launch Polish

> **Spec:** `tasks/vat-vies/spec.md`
> **Plan:** `tasks/vat-vies/pre-launch-plan.md`
> **Review:** `review.md` (F-009, F-012, F-014, F-015, F-016, F-019, F-022, F-025, F-032)
> **Status:** 📋 PLANNED — runs after all feature work, before first real tenant onboards
> **Depends on:** All other tasks landed (hotfix, legal-references, pdf-rewrite, domestic-exempt, blocks, invoice-credit-debit, blocks-credit-debit, tenant-plan, partner-plan, invoice-plan)

---

## Why this task exists

The review surfaced a bundle of items that are **correctness / compliance / UX polish** — each one small, none blocking a single feature, but collectively they form the "don't ship without this" list for the first real tenant.

Some of these (F-019 staleness, F-025 VIES raw address) are already absorbed into `partner-plan.md`. This file is a consolidator — it tracks the remaining Medium/Low items that don't have a natural home elsewhere.

---

## Scope

| Finding | What | Where |
|---------|------|-------|
| F-009 | Reverse-charge override recency gate + alt-proof acknowledgement | Already in `invoice-plan.md` — verify landed |
| F-012 | GDPR / DSAR / retention posture documentation | New section in `spec.md` + DSAR action on Partner view |
| F-014 | OSS threshold approaching warning (80%/100% banners) | Dashboard widget + invoice-form soft warning |
| F-015 | Exchange-rate source documented + `ForeignExchangeService` | New service + spec.md entry |
| F-016 | Retention policy — 10 years; `document_hash` column for integrity | Migration + service hook |
| F-019 | Pending partner staleness | Already in `partner-plan.md` — verify |
| F-022 | OSS coverage for services verified | New regression test; document product service-category tagging for future |
| F-025 | VIES raw address storage | Already in `partner-plan.md` — verify |
| F-032 | Invoice numbering integrity audit | Verification + regression test |

---

## Non-scope

- F-003 ВИЕС-декларация / ECSL (backlog, full reporting module)
- F-020 BG SME regime (backlog)
- F-026 SUPTO fiscal-printer mapping (cross-team, backlog)
- F-033 Advance payments / prepayments (backlog)
- ViDA / Peppol / national e-invoicing mandates (backlog)

---

## Known Changes

### GDPR / DSAR (F-012)

- New section in `spec.md` — "Privacy & Retention" — declaring lawful basis (GDPR Art. 6(1)(c)), retention window (10 years), erasure restrictions during retention
- New action on Partner view: "Data subject request" — logs the request into activity log + emails the tenant admin + offers a button to export the partner's data as JSON / CSV (for Art. 15 access)
- Partner export: JSON dump of partner fields + invoice counts (no invoice content — that's the tenant's data) with redaction support

### OSS threshold warning (F-014)

- Dashboard widget showing `EuOssAccumulation` for current year: `€X / €10,000` with colour ring
- Yellow at 80% (`€8,000`), red at 100%
- Invoice form soft warning: if draft invoice would push accumulation past threshold, show a banner before submit
- Emit a persistent Filament notification when threshold first crossed; log activity

### Currency / FX (F-015)

- New service `app/Services/ForeignExchangeService.php` — single source for all conversions
- Reads rates from configured source (default: BNB; fallback: ECB) per tenant country
- Signature: `convert(string $from, string $to, float $amount, \DateTimeInterface $at): float`
- All call sites (CustomerInvoiceService, EuOssService, CreditNoteService, DebitNoteService) route through this one service
- Migration: add `exchange_rate_source` string column on `customer_invoices` for audit trail of which source was used
- Spec.md entry: "Exchange rate = BNB reference on chargeable-event date by default; ECB fallback; rounded to 5 decimals; tax base rounded to 2 decimals half-up"

### Retention (F-016)

- Add `document_hash` char(64) column on `customer_invoices`, `customer_credit_notes`, `customer_debit_notes` — SHA-256 of a canonical serialization of the invoice at confirmation time
- Store hash in the confirmation transaction; never writable after
- Artisan command `hmo:integrity-check` — recomputes hash per document and reports mismatches
- Tenant deletion flow: block hard-delete when confirmed documents exist within the retention window; offer archive export instead (coordinate with the tenant-lifecycle task per CLAUDE.md memory `project_tenant_lifecycle`)
- spec.md section: "Retention & integrity — 10 years from end of fiscal year; document_hash pinned at confirmation; tenant hard-delete gated on retention window"

### Invoice numbering audit (F-032)

- Verify numbering service: allocates at confirmation (not draft creation); concurrent-confirmation-safe (row lock / dedicated sequence table)
- Verify BG format: 10-digit, zero-padded, Arabic numerals only, per-tenant sequence
- Add regression test: delete a draft, confirm the next → number sequence remains contiguous
- Document the numbering rule per tenant country (backlog: make configurable when expanding)

### OSS service coverage (F-022)

- Add a regression test confirming `EuOssAccumulation::accumulate()` is invoked for invoices whose items are services (not just goods)
- Document in `spec.md` that services with Art. 47 (immovable property), Art. 53/54 (event admission), Art. 48-52 (passenger transport), Art. 55 (restaurant/catering) require separate product-category tagging to avoid mis-routing to `EuB2c*` — flag for a future products-vat-classification task

---

## Tests Required

- [ ] Feature: GDPR — Partner "Data subject request" action creates an activity log entry + emits an event
- [ ] Feature: OSS threshold dashboard widget renders current accumulator + colour
- [ ] Feature: Invoice form banner when accumulator would cross threshold
- [ ] Feature: First threshold crossing emits a persistent notification (one-time per year)
- [ ] Feature: `ForeignExchangeService::convert()` returns known values for known BNB / ECB dates
- [ ] Feature: Invoice's `exchange_rate` + `exchange_rate_source` are populated at confirmation via the service
- [ ] Feature: All conversion call sites (EuOssService::accumulate, adjust, CustomerInvoiceService) go through `ForeignExchangeService`
- [ ] Feature: `customer_invoices.document_hash` is populated at confirmation
- [ ] Feature: `hmo:integrity-check` reports a mismatch when the serialized invoice differs from stored hash
- [ ] Feature: Tenant hard-delete is blocked when confirmed documents exist
- [ ] Feature: Invoice numbering regression — delete draft → next draft confirmed → contiguous sequence
- [ ] Feature: OSS accumulation covers invoices whose items are services (not goods-only)

---

## Refactor Findings

> Filled during / after implementation.

---

## Checklist

- [x] Investigation complete
- [x] Plan written (`pre-launch-plan.md`)
- [ ] F-012 GDPR section + DSAR action
- [ ] F-014 OSS threshold warning UI
- [x] F-015 FX service + audit column (`exchange_rate_source` pinned at confirmation; `DocumentHasher::resolveExchangeRateSource`)
- [x] F-016 document_hash + integrity command (`DocumentHasher`, `pinDocumentData` in all 3 services, `hmo:integrity-check` command; tenant-delete gate deferred to lifecycle task)
- [ ] F-022 service OSS coverage test
- [ ] F-032 invoice numbering audit + regression test
- [ ] Automated tests pass
- [ ] Browser-tested end-to-end on a scratch tenant
- [ ] Pint clean
- [ ] Final test run green
- [ ] Reviewer sign-off before onboarding first real tenant
