# VAT / VIES — Plan & Implementation Review

> **Scope:** All files under `tasks/vat-vies/` + implemented code for Areas 1–3 (tenant setup, partner VAT+VIES, invoice VAT confirmation). Phase C (credit/debit notes) and Area 4 (blocks for non-VAT-registered tenants) reviewed at plan level only.
> **Reviewer:** Claude (Opus 4.7), orchestrating subagent research
> **Started:** 2026-04-17
> **Status:** Complete draft — 27 findings across four categories (Legal, Accounting, UX, Security)

---

## Executive Summary

**Bottom line:** The architectural foundations (VatScenario enum, three-state partner VAT, VIES audit trail, reverse-charge override with RBAC + audit) are **sound and well-designed**. The legal-reference data model planned in Phase A is correct. The confirmation flow's 5-scenario (6 with Exempt) logic maps cleanly to EU VAT Directive 2006/112/EC.

**Five Critical issues — any one of which independently makes invoices issued by the current implementation formally defective or tax-delinquent under BG / EU law:**

1. **[F-030] LIVE BUG — potential tax under-declaration.** `VatScenario::determine()` routes any partner with null `country_code` to `NonEuExport` (0% VAT), including partners that are in fact BG-domestic customers. `partner.country_code` is nullable at the DB level. Invoices confirmed since Areas 1–3 shipped may be silently under-charging VAT. **Immediate fix + remediation query required.**
2. **[F-001]** The invoice PDF does not render the legal-reference line mandated by **Art. 226(11) / чл. 114, ал. 1, т. 12 ЗДДС** for any of the four zero-rate scenarios (Exempt, EU B2B reverse charge, EU B2C destination, Non-EU export). Phase A's `vat_legal_references` table holds the exact citations but is **planned but not yet implemented**, even though Areas 1–3 have already shipped.
3. **[F-002] (see also F-029)** The same PDF is missing several other Art. 226 requirements: date of chargeable event, per-rate VAT breakdown, localized "обратно начисляване" wording, full supplier legal address, and — separately cited at F-029 — the mandatory Bulgarian heading "**ФАКТУРА**" instead of the hard-coded "INVOICE".
4. **[F-003]** The EU B2B `EuB2bReverseCharge` rating depends under the 2020 Quick Fixes on timely filing of the recapitulative statement (**ВИЕС-декларация / ECSL**, чл. 125 ЗДДС). The app models none of this; absent filing, the 0% rating is challengeable by НАП.
5. **[F-031] VERIFIED MISSING — confirmed-invoice immutability guard.** Grep against `app/Models/CustomerInvoice.php` returned zero matches for `RuntimeException` / `updating(` / `deleting(` model-event guards. A confirmed invoice is a legal record; it must be immune to in-place edits / deletes per Art. 233 Directive and чл. 114, ал. 6 ЗДДС. StockMovement has this pattern already (per CLAUDE.md) — the same RuntimeException guard is needed on CustomerInvoice.

**Recommended next actions, in order:**

1. **Hotfix today** — F-030 (country_code routing). Change `determine()` to throw on null / empty `country_code`; add DB NOT NULL migration; run an audit query over confirmed `NonEuExport` invoices and flag any with a likely-domestic partner for tax remediation. This is the only item that may have already produced incorrect tax filings.
2. **Stop-gap PDF hotfix** — land Phase A (legal references table) **immediately**; extend the invoice PDF to render the resolved reference line + BG document heading. This closes F-001 / F-004 / F-029.
3. **Parallel** — rewrite the invoice PDF to comply with Art. 226 (F-002). Deploy as a print-time change (no document re-issuance needed for back-catalogue because the underlying data is on file — except the F-030 remediation set).
4. **Verify immutability** — F-031. If the Confirmed-invoice `RuntimeException` guard is missing, this escalates to Critical.
5. **Before any non-BG tenant onboards** — model the goods/services split (Phase B `vat_scenario_sub_code`), fix the OSS-year bug (F-006), and document GDPR / retention posture (F-012, F-016).
6. **Before scaling EU B2B** — either build ВИЕС-декларация export (full fix F-003) or at minimum publish a clear disclaimer + monthly CSV export for the tenant's accountant.
7. **Before Phase C** — resolve F-021 (credit-note inheritance vs blocks override) and document the partner + tenant drift semantics; fix the partner-mutation transactional boundary (F-024).

**Non-blocking but material:** 24+ additional Medium / Low findings covering UX, data quality, audit-trail edge cases, prepayments (F-033), invoice-number integrity (F-032), and per-MS-expansion gaps. Full list in the Summary Table below.

**Out-of-scope items** worth tracking for the broader roadmap: чл. 117 protocols (inbound reverse charge), Art. 141 triangulation, Art. 199 domestic reverse charge, margin schemes, ViDA (mandatory structured e-invoicing for intra-EU B2B by 2030-07-01), national e-invoicing mandates (DE, FR, IT, PL, RO), OSS/IOSS quarterly returns, SAF-T, Intrastat, and the BG SUPTO / fiscal-printer tax-group mapping.

**Reviewer confidence:** High on the legal citations (primary sources: consolidated Directive 2006/112/EC, CIR 282/2011, NAP guidance on лex.bg/nap.bg). Several Bulgarian-law citations remain **UNVERIFIED** against primary source text where the lex.bg article pages could not be retrieved programmatically (exact subpoint numbering of чл. 114, ал. 1; exact EUR figure in чл. 99; ППЗДДС exchange-rate rule numbering). These are flagged inline and listed in the verification checklist appended below. Treat UNVERIFIED citations as strong working hypotheses, not legal conclusions — confirm before acting.

---

## Post-Review Verification Checklist (UNVERIFIED items to confirm before acting)

| # | Item | Where to verify |
|---|------|-----------------|
| V1 | Exact subpoint numbering of чл. 114, ал. 1 ЗДДС (invoice content) | `lex.bg/laws/ldoc/2135533201` |
| V2 | Exact ал. reference for the 5-day credit/debit-note rule (чл. 115) | same |
| V3 | Exact ал. reference for "обратно начисляване" mandatory wording (likely чл. 114, ал. 4 — NOT чл. 86(4)) | same |
| V4 | EUR figure for intra-EU acquisition threshold (чл. 99 ЗДДС) post-Euro | same |
| V5 | BG voluntary non-registration / cross-border SME article numbering in 2026 consolidated text | same + НАП SME regime announcement |
| V6 | ППЗДДС exchange-rate article (BNB vs ECB) | `lex.bg/laws/ldoc/2135534826` |
| V7 | Actual stancl/tenancy cache bootstrapper configuration in this project | `config/tenancy.php` |
| V8 | Whether `EuOssAccumulation::accumulate()` is called for service invoices, not just goods | grep call sites |
| V9 | Current Intrastat thresholds for 2026 (EUR figures) | НСИ / stat.bg |
| V10 | Whether the invoice PDF template already contains any supplier-address field I missed | `resources/views/pdf/customer-invoice.blade.php` top-to-bottom |

---

## Methodology

Reviewed against current sources of truth:

- **EU VAT Directive** 2006/112/EC (consolidated)
- **Council Implementing Regulation** (EU) 282/2011 (VAT implementing rules)
- **VIES** technical spec (SOAP `checkVatService`, REST variant where applicable) and Art. 31 CIR 282/2011 (verification of VAT numbers)
- **Bulgarian ЗДДС** (Закон за данък върху добавената стойност) as in force on 2026-04-17 (post-Euro adoption, 01 Jan 2026)
- **Bulgarian ППЗДДС** (regulation for implementing ЗДДС) for document content requirements
- **ЗСч** (Закон за счетоводството) for invoice-retention periods and document integrity

### Severity scale

| Severity | Meaning |
|----------|---------|
| **Critical** | Current-law violation that would invalidate a document, trigger tax liability, breach GDPR, or expose security risk. Fix before release. |
| **High** | Clear functional or legal risk. Fix before the phase it lives in closes. |
| **Medium** | Correctness, consistency, or UX issue. Should be addressed before Phase C closes. |
| **Low** | Polish, docs, or future-work item. Track but do not block. |

### Categories

Legal · Accounting · UX · Security

### Finding template

```
## [FINDING-ID] <title>
**Severity:** Critical | High | Medium | Low
**Category:** Legal | Accounting | UX | Security
**Affects:** <file:line or plan area>
**Issue:** <what is wrong>
**Source of truth:** <ЗДДС/EU Directive/VIES spec citation>
**Recommendation:** <concrete fix, or "FLAG — recommendation required">
**Status:** Open | Acknowledged | Resolved | Won't-fix
```

---

## Summary Table

| ID | Title | Severity | Category | Status |
|----|-------|----------|----------|--------|
| F-001 | Invoice PDF omits exemption legal reference (Art. 226(11)) | **Critical** | Legal | ✅ Resolved — shipped in pdf-rewrite.md |
| F-002 | PDF lacks other Art. 226 mandatory content (date of supply, per-rate VAT, localized "Reverse charge", full supplier address) | **Critical** | Legal | ✅ Resolved — shipped in pdf-rewrite.md |
| F-003 | No recapitulative statement (VIES declaration / ECSL) — Art. 138 substantive condition unmet | **Critical** | Legal · Accounting | Open — HIGH-PRIORITY gap |
| F-004 | Stale "Art. 96 ЗДДС" citation in blocks.md and blocks-credit-debit.md | High | Legal | ✅ Resolved — shipped in pdf-rewrite.md |
| F-005 | VIES cache key not per-tenant at application layer | High (Critical if bootstrapper off) | Security · Legal | ✅ Resolved — shipped in hotfix.md |
| F-006 | OSS threshold uses `now()->year`, not the invoice's chargeable-event year | High | Legal · Accounting | ✅ Resolved — shipped in invoice-plan.md |
| F-007 | Non-EU B2B services mis-classified as `NonEuExport` | High | Legal · Accounting | ✅ Resolved — shipped in invoice-plan.md |
| F-008 | Silent partner downgrade on VIES invalid | Medium | UX · Legal · Accounting | ✅ Resolved — shipped in partner-plan.md |
| F-009 | "Confirm with Reverse Charge" override legal-risk caps missing | High | Legal | ✅ Resolved — shipped in invoice-plan.md |
| F-010 | 5-day credit/debit-note window not enforced (чл. 115 ЗДДС) | Medium | Legal · Accounting | ✅ Resolved — shipped in invoice-credit-debit.md |
| F-011 | Credit/debit note PDF doesn't reference the original invoice date | Medium | Legal | ✅ Resolved — shipped in pdf-rewrite.md |
| F-012 | GDPR posture for sole-proprietor VAT numbers not documented | Medium | Security · Legal | ✅ Resolved — shipped in pre-launch.md |
| F-013 | VIES consultation number not shown on invoice PDF | Medium | Legal · Accounting | Open |
| F-014 | No OSS threshold-approaching warning | Medium | UX · Accounting | ✅ Resolved — shipped in pre-launch.md |
| F-015 | Exchange-rate source and rounding rule not documented | Medium | Accounting · Legal | ✅ Resolved — shipped in pre-launch.md |
| F-016 | No retention policy for VAT records (10-year BG) | Medium | Legal · Security | ✅ Resolved — shipped in pre-launch.md |
| F-017 | Spec / impl drift — VIES cooldown (spec says localStorage, code uses server) | Low | UX · Doc | ✅ Resolved — shipped in hotfix.md |
| F-018 | `checkVatApprox` WSDL uncertainty is stale | Low | Doc | ✅ Resolved — shipped in hotfix.md |
| F-019 | `VatStatus::Pending` semantics under-specified outside invoice flow | Medium | Legal · UX | ✅ Resolved — shipped in partner-plan.md |
| F-020 | 2026-01-01 BG SME regime / new threshold / new basis not modelled | Medium | Legal · Accounting | Open |
| F-021 | Credit-note inheritance vs partner/tenant status drift (blocks override bug) | Medium | Legal · Accounting | ✅ Resolved — shipped in blocks-credit-debit.md |
| F-022 | OSS coverage for TBE services and special-rule services not verified | Medium | Legal · Accounting | ✅ Resolved — shipped in pre-launch.md |
| F-023 | Tenant without own VAT gets null `request_id` on reverse-charge invoices | High | Legal · Accounting | ✅ Resolved — shipped in tenant-plan.md |
| F-024 | Partner mutation not inside invoice-confirmation transaction | Medium | Security · Accounting | ✅ Resolved — shipped in invoice-plan.md |
| F-025 | VIES address "best-effort parse" — PDF address quality risk | Low | UX · Data quality | ✅ Resolved — shipped in partner-plan.md |
| F-026 | SUPTO / fiscal-printer mapping from VAT scenario not documented | Medium | Legal · Accounting | Open — cross-team |
| F-027 | No path for Art. 18(1)(b) "VAT applied-but-not-yet-issued" fallback | Low | Legal | Open — future work |
| F-028 | Invoice 5-day issuance rule (чл. 113, ал. 4 ЗДДС) not enforced | High | Legal · Accounting | ✅ Resolved — shipped in pdf-rewrite.md |
| F-029 | PDF heading reads "INVOICE"; BG requires "ФАКТУРА" (чл. 114, ал. 1, т. 1) | High | Legal | ✅ Resolved — shipped in pdf-rewrite.md |
| F-030 | `VatScenario::determine()` routes empty `country_code` to `NonEuExport` — silent 0% VAT on domestic sales (**live bug**) | **Critical** | Legal · Accounting | ✅ Resolved — shipped in hotfix.md |
| F-031 | Confirmed-invoice immutability guard absent (чл. 114, ал. 6; Art. 233) — VERIFIED MISSING | **Critical** | Legal · Security | ✅ Resolved — shipped in hotfix.md |
| F-032 | Invoice number sequential / no-gaps guarantee (чл. 114, ал. 1, т. 2) not verified | Medium | Legal · Accounting | ✅ Resolved — shipped in pre-launch.md |
| F-033 | Advance payments / prepayments chargeable event not modelled (Art. 65; чл. 25, ал. 7 ЗДДС) | Medium | Legal · Accounting | Open — future phase |
| F-034 | Legacy `VAT-DETERMINATION-1` code — VERIFIED gone from `app/`, memory cleanup only | Low | Doc · Architecture | **Resolved** |
| F-035 | `blocks-credit-debit.md` references `applyExemptScenario()` with no definition | Low | Doc · Planning | ✅ Resolved — shipped in blocks-credit-debit.md |
| F-036 | `invoice.md` ↔ `invoice-plan.md` drift on `$ignorePartnerVat` | Low | Doc hygiene | ✅ Resolved — shipped in hotfix.md |

---

## Out-of-Scope / Future Work

Collected incrementally — items that belong in a later phase but don't block the current plans. Each item is a pointer, not a finding.

### Outgoing-document VAT mechanics not yet covered

- **Art. 141 triangulation simplification** — A→B→C chains across three MS. Requires special invoice mention ("Reverse charge" + reference to Art. 197) and specific place-of-supply treatment. Add when any tenant signals an ABC flow.
- **Art. 199 domestic reverse charge** for specific supplies (construction, scrap, waste, emission allowances, mobile phones, ICs) — BG has transposed parts via чл. 82(5) and чл. 163а ЗДДС. Relevant for tenants in construction / scrap / telecom. New `VatScenario::DomesticReverseCharge` case + user-selected sub-code.
- **Art. 194** (non-established supplier reverse charge to local customer) — post-ViDA 2028-07-01 this becomes **mandatory** Union-wide; plan to retire the MS-option ambiguity then.
- **Art. 199a / 199b** (fraud-sensitive reverse charge, quick-reaction mechanism) — optional for each MS; scope as needed.
- **Art. 226(10a) self-billing** — customer issues the invoice in supplier's name. Requires explicit agreement; the mention "Self-billing" is mandatory on the invoice.
- **Art. 226(7a) cash accounting** — BG чл. 151а ЗДДС. Mandatory mention "Cash accounting" on the invoice. Add a tenant-level flag if cash-accounting scheme is elected.
- **Margin schemes** (Art. 226(11b–d)) — travel agents, second-hand goods, works of art / antiques / collectibles. Separate enum value + separate totals rendering (no per-item VAT, margin-based).
- **New means of transport** (Art. 226(12); BG чл. 7, ал. 5 ЗДДС) — special intra-EU B2C regime.
- **Tax representative** (Art. 226(15)) — when a foreign supplier has a BG tax representative, their number + name + address must appear on the invoice.
- **Simplified invoices** (Art. 220a, Art. 226b) — MS may permit a reduced data set up to EUR 100 (optional up to EUR 400). Check BG transposition before enabling.
- **Foreign-currency conversion strategy** — per-tenant configurable (BNB vs ECB), see F-015.

### Inbound (tenant-as-buyer) obligations — not modelled

- **чл. 117 ЗДДС protocol** (self-invoice for reverse-charge recipient). When the tenant receives a reverse-charge supply from a foreign supplier, they must issue a protocol within 15 days. Separate document type; separate form; goes on the monthly purchase journal.
- **Intra-Community acquisition** registration (чл. 99 ЗДДС, EUR 20 000 threshold if historical BGN 20 000 transposed) — even non-VAT-registered tenants can trip this. The app should track ICA totals per tenant per calendar year.
- **Inbound Intrastat arrivals** reporting (EUR 899 874 threshold in 2026 — verify against НСИ).

### Reporting / declarations

- **ВИЕС-декларация** (recapitulative statement, чл. 125 ЗДДС) — see F-003.
- **Дневник покупки / продажби** (monthly VAT purchase / sales registers). Tax-period-grouped exports of all invoices, credit/debit notes, protocols. Required with the monthly справка-декларация.
- **Справка-декларация по ЗДДС** (monthly VAT return).
- **OSS / IOSS quarterly returns** (Title XII, Ch. 6 Sections 2-4) — currently only accumulation is tracked; return generation is not.
- **Intrastat dispatches / arrivals** reporting (monthly, threshold-gated per НСИ).
- **SAF-T** (Standard Audit File for Tax) — phased BG rollout; large taxpayers first; design early so the data model allows extraction.

### e-Invoicing mandates (per-MS)

- **ViDA Pillar 1** — from OJ entry-into-force: MS may mandate domestic e-invoicing without Council pre-authorisation. From 2030-07-01: **mandatory EN 16931-compatible e-invoicing for intra-EU B2B** + near-real-time digital reporting replacing the recapitulative statement.
- **DE** — B2B e-invoicing obligation phased from 2025-01-01 (receipt mandatory; issuance by 2027-01-01 or 2028 depending on turnover).
- **IT** — SdI mandatory (since 2019) — must integrate for any IT tenant.
- **FR** — Factur-X / PPF planned 2026-09 receipt, 2026-09→2027-09 issuance.
- **PL** — KSeF mandatory in phases.
- **RO** — e-Factura mandatory since 2024.
- **HU** — RTIR realtime reporting.
- **Peppol BIS Billing 3.0** — de-facto EU B2B format; plan to emit EN 16931-compatible XML alongside PDF.
- **BG** — SAF-T rollout plus (at time of writing) no B2B e-invoicing mandate yet; monitor НАП announcements.

### 2020 Quick Fixes (Directive (EU) 2018/1910) — two of four not modelled

- **Call-off stock** (Art. 17a) — goods sent to a known buyer in another MS, transfer of ownership deferred. Currently treated as an immediate intra-EU supply — wrong for call-off.
- **Chain transactions** (Art. 36a) — assignment of transport to a single leg. Relevant once any tenant acts as an intermediary supplier in a chain.

### Privacy / retention / integrity

- GDPR — see F-012.
- Retention policy — see F-016.
- Document-integrity hash / append-only guarantee — see F-016 recommendation 2.

### Per-MS expansion gaps

- Phase A seeds legal references **only for Bulgaria**. Every additional MS tenant requires its own seed set covering Exempt, domestic exemptions, and the goods/services split for EU B2B / Non-EU supplies.
- Per-MS VAT rate tables (`EuCountryVatRate` seeder) must be kept current — EU Commission publishes quarterly rate changes. Consider sourcing from TEDB (Taxes in Europe Database).
- Per-MS VAT number regex / checksum tables — currently in `EuCountries`. BG `mod-11` EIK checksum and per-MS VAT checksums (DE `BZSt-11`, FR `clé` algorithm, etc.) should be validated client-side before sending to VIES to reduce useless API calls.
- Per-MS invoice-number format rules — BG requires 10-digit sequential Arabic numerals without gaps (чл. 114, ал. 1, т. 2). Other MS vary. Document the per-MS rule and parametrise the numbering service.
- Per-MS retention windows (see F-016).

### SUPTO / fiscal-printer cross-cuts

- See F-026.
- **Наредба Н-18** — BG tenants with cash-register obligation. Fiscal-receipt content, tax-group coding, SUPTO audit trail (export to NRA). Cross-cuts with VAT scenarios at issuance time.

### Post-Euro-adoption transition items (BG, 2026)

- **Dual-display on consumer-facing prices** (2025-08-08 → 2026-08-08) — pricing UI obligation, not invoice-face obligation. Track in pricing / POS area, not here.
- **Dual-currency totals on fiscal receipts** during dual-circulation (Jan 2026) — fiscal-printer concern.
- **Legacy BGN documents** — remain legally valid at original values; no reissue required. Storage must preserve original currency; PDF rendering must not auto-convert historical BGN amounts.

### Architecture / testability

- **Phase A landing gap** — `vat_legal_references` table is the lynchpin of F-001 and F-004. Merging it is a prerequisite to fixing those.
- **Two load-bearing seeds** — Phase A plan calls out that `TenantOnboardingService` and `TenantTemplateManager` must both call the legal-references seeder; any drift between those two breaks tests silently. Add a CI invariant: hash(seeded rows) appears in both places, fail-loudly if not.
- **Invariant enforcement at DB level** — `is_vat_registered=true ⇔ vat_number IS NOT NULL` is enforced at service layer only. Add a DB `CHECK` constraint (PostgreSQL): `CHECK (NOT is_vat_registered OR vat_number IS NOT NULL)`. Same for Partner.
- **VAT number checksum** — validate before sending to VIES. Reduces rate of VIES `invalid` responses caused by typos; protects against the silent-downgrade in F-008.

---

## Findings

_(Per-discovery; newest at the bottom.)_

---

## [F-001] Invoice PDF omits the exemption legal reference required by Art. 226(11)
**Severity:** Critical
**Category:** Legal
**Affects:** `resources/views/pdf/customer-invoice.blade.php:157-162` (current template), `app/Services/CustomerInvoiceService.php` (no sub-code stored), Phase A plan (`vat_legal_references` table is planned but **not yet implemented**; Areas 1–3 have already shipped without it).

**Issue:** Every invoice carrying one of the four "requires-rate-change" scenarios (`Exempt`, `EuB2bReverseCharge`, `EuB2cOverThreshold`, `NonEuExport`) must bear, on the face of the invoice, a **reference to the applicable provision** that justifies the exemption / zero-rate / reverse charge. The current PDF template contains no such reference — it only renders a single `is_reverse_charge` text block (in English, generic). A `NonEuExport` or `Exempt` invoice renders with **no legal basis line at all**. Invoices issued since Area 3 went live are formally defective under both EU and BG law.

**Source of truth:**
- **Art. 226(11) Directive 2006/112/EC** — "in the case of an exemption, **reference to the applicable provision of this Directive, or to the corresponding national provision, or any other reference indicating that the supply of goods or services is exempt**."
- **чл. 114, ал. 1, т. 12 ЗДДС** — requires "основание за прилагане на нулева ставка или за неначисляване на данък" on the invoice face.
- **Art. 226(11a)** — the literal mention "**Reverse charge**" is also mandatory where the customer is liable. CJEU **C-247/21 Luxury Trust Automobil** confirmed this is a substantive requirement: absence cannot be cured retroactively.

**Recommendation:**
1. **Treat as a release blocker for any tenant currently issuing cross-border invoices.** Until fixed, either force `Domestic` scenarios only, or render a temporary hard-coded legal-notice line until Phase A lands.
2. Accelerate **Phase A** (legal-references table) — it is the unlock for the correct line on the PDF. Phase A plan already seeds `чл. 113, ал. 9 ЗДДС` (Exempt), `Art. 138 Directive 2006/112/EC` (EU B2B goods), `Art. 44 + 196 Directive 2006/112/EC` (EU B2B services), `Art. 146 Directive 2006/112/EC` (non-EU export goods), `Art. 44 Directive 2006/112/EC (outside scope of EU VAT)` (non-EU export services).
3. Add a migration now to introduce `customer_invoices.vat_scenario_sub_code` **or** at minimum an always-resolvable "goods" default, so the PDF can already display the right line for the three binary scenarios (Exempt, EU-B2B-goods, Non-EU-export-goods) while Phase B's goods/services split is wired.
4. Add a PDF block that always renders when `vat_scenario !== Domestic` and the scenario's resolved legal reference is non-null. Block location: below the meta box, before the line-items table.
5. Back-fill: for every confirmed invoice already in DB with a non-Domestic scenario and no resolved reference, either (a) render the reference at print time from the lookup table + stored `vat_scenario` (falling back to `default` sub-code), or (b) mark those invoices for re-issuance if the BG tax authority requires a reissued document. This is a **legal-risk call** that should be escalated to the tenant's accountant, not decided in code.

**Status:** ✅ Resolved — shipped in pdf-rewrite.md

---

## [F-002] PDF lacks Art. 226-mandated invoice content beyond the exemption reference
**Severity:** Critical
**Category:** Legal
**Affects:** `resources/views/pdf/customer-invoice.blade.php` (whole template)

**Issue:** Independent of F-001, the current PDF template is missing several other Art. 226 mandatory fields:

1. **Date of supply / chargeable event** (Art. 226(7); чл. 114, ал. 1, т. 11 ЗДДС) — only `issued_at` is rendered ("Date: dd.mm.yyyy"). If the chargeable event differs from the issue date (e.g. prepaid goods, deferred delivery, services completed earlier), it must be shown separately. No field, no render.
2. **Taxable amount per VAT rate** (Art. 226(8); чл. 114, ал. 1, т. 12 ЗДДС) — the totals block shows a single `VAT:` line summing all items. BG/EU law requires a **per-rate breakdown**: subtotal-at-20%, VAT-at-20%, subtotal-at-9%, VAT-at-9%, subtotal-at-0%, etc. A BG invoice with mixed-rate lines cannot legally show a single consolidated VAT total.
3. **Reverse-charge wording is not localized** (Art. 226(11a); чл. 114, ал. 4 ЗДДС). The template prints "Reverse Charge — VAT accounted for by the recipient" in English. For invoices issued from a BG tenant to a BG-identified event chain (or when the invoice is audited by НАП), the literal phrase "**обратно начисляване**" is expected. The EU directive allows any equivalent local-language rendering, but for a multi-language EU-wide SaaS, the phrase should be rendered in the tenant's invoicing locale at minimum.
4. **Customer VAT identification number** (Art. 226(4)) is rendered only if present (line 139). Good — no gap. But there is **no guard** that, for an `EuB2bReverseCharge` invoice, the partner's confirmed VAT number is on the face. The confirmation flow should refuse to issue a reverse-charge invoice if `$invoice->partner->vat_number` is null.
5. **Supplier address** (Art. 226(5)) — only `EIK / VAT / email` are rendered for the tenant. The **full legal address** of the supplier is missing from `tenant(...)` calls. BG law (чл. 114, ал. 1, т. 4) explicitly requires the supplier's address.
6. **Sequential number** (Art. 226(2); чл. 114, ал. 1, т. 2 ЗДДС) — BG additionally requires the invoice number to be **exactly 10 digits, Arabic numerals, no gaps**. Verify the numbering service enforces this; if not, it is a separate finding (tracked as F-FUTURE below because it is outside the VAT/VIES scope).

**Source of truth:**
- Art. 226 Directive 2006/112/EC
- чл. 114, ал. 1 ЗДДС (points 1–15, exact BG enumeration verifiable on `lex.bg/laws/ldoc/2135533201`)
- CJEU C-247/21 for literal mention requirement

**Recommendation:**
1. Extend the invoice model with / ensure presence of: `supplied_at` (date of chargeable event, distinct from `issued_at`), full tenant legal address stored in `CompanySettings`.
2. Refactor the totals block to render **one row per distinct VAT rate present on the invoice** (group items by rate, emit `Net @ rate%` + `VAT @ rate%` rows, then the grand total).
3. Localize the "Reverse charge" wording. Store the PDF rendering locale on the invoice (tenant default is fine) and use a translation file: BG → "обратно начисляване", EN → "Reverse charge", DE → "Steuerschuldnerschaft des Leistungsempfängers", etc.
4. Add a service-layer guard in `CustomerInvoiceService::confirmWithScenario()`: if scenario is `EuB2bReverseCharge` and `$invoice->partner->vat_number` is null → hard-fail with a clear error. (This is a side-invariant of F-001.)
5. Treat this as a blocker at the same priority as F-001. Same "reissue vs accept" call for already-issued docs.

**Status:** ✅ Resolved — shipped in pdf-rewrite.md

---

## [F-003] No recapitulative statement (VIES declaration / EC Sales List) — substantive condition for 0% intra-EU rating is not met
**Severity:** Critical
**Category:** Legal · Accounting
**Affects:** Whole feature — no module/file exists

**Issue:** The 2020 Quick Fixes (Directive (EU) 2018/1910) elevated two formerly "formal" conditions into **substantive** conditions for exempting intra-EU B2B supplies of goods under Art. 138:
1. The acquirer's VAT number must be valid in VIES (✅ implemented).
2. **The supplier must file a correct recapitulative statement** (Art. 262 Directive / чл. 125 ЗДДС — ВИЕС-декларация, monthly, by the 14th of the following month) listing every intra-EU B2B customer by VAT number, with totals per customer per month.

The app performs the VIES check but **has no mechanism** to generate, submit, or even aggregate the monthly ВИЕС-декларация. An app that issues `EuB2bReverseCharge` invoices without a corresponding declaration creates **inaccurate primary tax records** and, per Art. 138(1a), **the 0% rating itself becomes challengeable** — НАП can recharacterise the supply as a domestic-rate sale and assess VAT against the tenant.

**Source of truth:**
- Art. 138(1a), Art. 262 Directive 2006/112/EC (as amended by Dir. 2018/1910)
- чл. 125 ЗДДС (monthly declaration), чл. 126 ЗДДС (corrections)
- Deadline: 14th of the following month

**Recommendation:**
1. **Scope decision required:** treat VIES declaration generation as part of the outgoing-invoice feature family, because the legal validity of `EuB2bReverseCharge` rating depends on it.
2. Short-term (before first production tenant issues EU B2B invoices):
   - Add a **user-facing disclaimer** on tenant onboarding: "Your accountant must file the ВИЕС-декларация by the 14th of the following month based on the EU B2B sales below" — plus a simple exported list (CSV: partner VAT, invoice number, net total, currency, period).
   - Store every `EuB2bReverseCharge` invoice so that aggregation per month is trivial (already true — `vat_scenario` is indexed).
3. Medium-term (before scaling beyond BG):
   - Build a monthly "ВИЕС-декларация preview" page that generates NRA's XML format.
   - Track `recapitulative_statement_period_id` on the invoice once filed (audit trail).
4. Flag for **legal advice per MS** — each EU MS has its own ECSL format (e.g. DE ZM, FR DEB/DES). This will be work for each MS expansion.

**Status:** Open — HIGH-PRIORITY design gap; does not invalidate current implementation (it is silent about the filing step), but user docs must make clear who is responsible.

---

## [F-004] Stale "Art. 96 ЗДДС" citation in `blocks.md` and `blocks-credit-debit.md`
**Severity:** High
**Category:** Legal
**Affects:** `tasks/vat-vies/blocks.md:20,56` (open-question), `tasks/vat-vies/blocks-credit-debit.md:59,110` (pseudo-code and PDF example cite `Art. 96 ЗДДС`)

**Issue:** Both plans cite "Art. 96 ЗДДС" as the basis for the "Not subject to VAT" legal notice printed on a non-VAT-registered tenant's invoice. **Art. 96 is the mandatory-registration threshold article**; it never appears on an issued document. The correct citation — already established and seeded correctly in `phase-a-plan.md` — is **чл. 113, ал. 9 ЗДДС**. NAP guidance on invoices issued by persons not registered under ЗДДС explicitly relies on чл. 113, ал. 9.

If Phase A is implemented, the data layer is correct. But until `blocks.md` and `blocks-credit-debit.md` are cleaned up, an implementing agent reading those files in isolation will write the wrong citation into product code.

**Source of truth:**
- чл. 113, ал. 9 ЗДДС (correct)
- чл. 96 ЗДДС (registration threshold — not an invoice-content rule)
- Already corrected in `tasks/vat-vies/phase-a-plan.md:6-10,227,245-246,274,336-337` and row 1 of the seed table.

**Recommendation:** Purge all "Art. 96 ЗДДС" references from `blocks.md` and `blocks-credit-debit.md`; replace with "чл. 113, ал. 9 ЗДДС" and add a `> **Legal citation resolved by:** Phase A — `vat_legal_references` table` pointer so future readers reach the correct seeded data. Add the same cross-reference in `spec.md`.

**Status:** ✅ Resolved — shipped in pdf-rewrite.md

---

## [F-005] VIES cache key is global (not per-tenant) at the application layer
**Severity:** High (Critical if the stancl/tenancy cache bootstrapper is NOT enabled in this project)
**Category:** Security · Legal (audit-trail integrity)
**Affects:** `app/Services/ViesValidationService.php:24` — `$cacheKey = "vies_validation_{$countryCode}_{$vatNumber}";`

**Issue:** The cache key is keyed only on `countryCode` + `vatNumber`. The cached payload includes the `request_id` (`requestIdentifier` returned by `checkVatApprox`), which is **tied to the requester** (the tenant's own VAT) — not just to the looked-up number. If two tenants check the same foreign VAT number inside the 24-hour TTL window:

1. Tenant A checks DE123 → VIES returns requestIdentifier `ABC-001` bound to A's requester info.
2. The response (including `ABC-001`) is cached under the global key.
3. Tenant B checks DE123 → gets the **cached** response with **Tenant A's** `requestIdentifier`.
4. Tenant B stores `ABC-001` on their own invoice as audit evidence.

If a tenant is ever asked to produce proof of the VIES check, their stored `requestIdentifier` will not match НАП's / Commission's records for their own VAT requester — the evidence collapses.

Whether this happens in practice depends on the stancl/tenancy `CacheTenancyBootstrapper` config. If enabled, Laravel's `Cache::remember` calls inside a tenant-initialised request will transparently prefix the key with the tenant ID and no cross-leak occurs. If disabled (or if any code path runs `Cache::remember` outside tenant context), leaks are possible.

**Source of truth:**
- `checkVatApprox` operation on VIES WSDL returns `requestIdentifier` only when requester information is supplied. The identifier is bound to `(requesterCountryCode, requesterVatNumber, date, lookedUpVatNumber)`.
- CIR 282/2011 Art. 18(1)(a) requires reliance on confirmation obtained by the supplier; "other tenant's proof" is not the same tenant's proof.

**Recommendation:**
1. Verify `stancl/tenancy` cache bootstrapper is registered in `config/tenancy.php`. If it is — add an **explicit test** that locks this down (two tenants, same VAT number, fresh response per tenant, different cache keys).
2. Regardless: add the tenant id to the cache key defensively. `"vies_validation_{tenantId}_{countryCode}_{vatNumber}"`. Defence-in-depth — trivial cost.
3. Additionally, document that the VIES requestIdentifier is stored at the **invoice level** (`customer_invoices.vies_request_id`), not at the Partner level. Good — cached leakage never reaches a confirmed invoice because `runViesPreCheck()` passes `fresh: true`. But the **Partner form's** "Check VIES" action reads cached data and could display a foreign `requestIdentifier` in the UI.
4. Consider whether caching successful VIES responses at all is desirable. If a VIES lookup is cheap, skipping the cache removes the whole class of issues. TTL of 24h on a compliance-critical lookup is defensible but not free.

**Status:** ✅ Resolved — shipped in hotfix.md

---

## [F-006] OSS threshold check uses wall-clock year, not the invoice's chargeable-event year
**Severity:** High
**Category:** Legal · Accounting
**Affects:** `app/Enums/VatScenario.php:58` — `EuOssAccumulation::isThresholdExceeded((int) now()->year)`; also `EuOssService` at accumulation sites (Phase B plan lines 72, 108).

**Issue:** The OSS EUR 10 000 threshold (Art. 59c Directive 2006/112/EC) is **calculated per calendar year**. The threshold relevant to an invoice is **the one for the year in which the chargeable event occurred**, not the year in which the invoice is being confirmed. If a user confirms a December-2025 invoice on 3 January 2026, using `now()->year = 2026` against the 2026 accumulator yields `EuB2cUnderThreshold` even though the 2025 accumulator was exceeded (and vice versa).

The Phase B plan already calls out the fix: `$invoice->issued_at->year` in both `accumulate()` and `adjust()`. It leaves `shouldApplyOss()` on `now()->year` because threshold-exceeded tests for *current* sales use current year — which is correct for forward-looking **form preview**, but wrong for **confirmation** of an invoice whose chargeable-event year differs.

**Source of truth:**
- Art. 59c Directive 2006/112/EC — EUR 10 000 EU-wide threshold, **per calendar year**, applied to the year the supply takes place.
- Art. 63–66 Directive — chargeable event = the moment supply is made (goods: dispatch; services: when carried out).
- BG: чл. 25 ЗДДС (данъчно събитие) + чл. 20б ЗДДС (transposition of Art. 59c).

**Recommendation:**
1. Land **Phase B's** fix in `EuOssService::accumulate()` (uses `$invoice->issued_at->year` — or better, `$invoice->supplied_at->year` once F-002 adds that field).
2. Add the same fix to `VatScenario::determine()` at confirmation time: when called from the confirmation flow, pass the invoice's year explicitly — **do not fall back to `now()->year`**. Change signature:
   ```php
   public static function determine(Partner $partner, string $tenantCountryCode, bool $ignorePartnerVat = false, bool $tenantIsVatRegistered = true, ?int $year = null): self
   ```
   Default to `now()->year` only for the form-preview call site. Confirmation flow MUST pass the invoice's year.
3. Add a regression test: a supply dated December 2025 confirmed in January 2026 — the scenario must be evaluated against the 2025 accumulator.
4. Cross-year credit notes (Phase C `adjust()`) must negatively reduce the parent's year accumulator, never the current year. Phase C plan already specifies this — flag to keep the test.

**Status:** ✅ Resolved — shipped in invoice-plan.md

---

## [F-007] Non-EU B2B services are mis-classified as `NonEuExport` — legal framing is wrong
**Severity:** High
**Category:** Legal · Accounting
**Affects:** `app/Enums/VatScenario.php:11-16` (enum) and `determine()` lines 42–44, 50–52 (no goods/services split); `phase-a-plan.md` explicitly acknowledges this; `invoice-plan.md`, `phase-b-plan.md` reference but do not fix.

**Issue:** The enum case `NonEuExport` is used for **all** supplies to non-EU customers. Legally:
- **Goods dispatched to a non-EU destination** → zero-rated export under Art. 146 Directive; BG чл. 28 ЗДДС.
- **Services to a non-EU business customer** → **outside the scope of EU VAT** under Art. 44 (place of supply = customer's country); BG чл. 21, ал. 2 ЗДДС. This is **not** an "export" — it is "not a taxable supply in the EU" at all.
- **Services to a non-EU consumer (B2C)** → governed by Art. 59 for specific service classes; else Art. 45 (origin-taxed).

The enum conflates three different legal treatments into one label. Phase A plan row 12 attempts to split this at the legal-reference layer (`goods` vs `services` sub-codes with different citations), but the enum itself is still a single bucket with one description: "Non-EU export — zero-rated (0% VAT)." Rendering that line for a service supply is wrong.

**Source of truth:**
- Arts. 44, 45, 59, 146 Directive 2006/112/EC
- BG: чл. 21, ал. 2 (services B2B → customer's country), чл. 28 (export of goods)
- Explanatory Notes on VAT invoicing rules — "outside scope of EU VAT" is a distinct label from "exempt" or "zero-rated".

**Recommendation:**
1. Keep the enum value (stable DB column) but update `description()` to be neutral: "Non-EU supply — zero-rated (goods) or outside scope of EU VAT (services)."
2. Drive the exact phrasing and legal reference from Phase A's `vat_legal_references` table based on the `vat_scenario_sub_code` (`goods` | `services`), **not** from the enum's `description()`.
3. Where the code currently calls `requiresVatRateChange()` — it returns true for `NonEuExport` (correct — 0% rate applied in both cases). No logic change needed; only the label/citation on the PDF and the modal.
4. Split at a later date if accounting rules diverge materially (e.g. for VAT-return reporting line).

**Status:** ✅ Resolved — shipped in invoice-plan.md

---

## [F-008] Partner VAT downgrade on VIES `invalid` is a silent side-effect of an invoice action
**Severity:** Medium
**Category:** UX · Legal (audit) · Accounting
**Affects:** `app/Services/CustomerInvoiceService.php` `runViesPreCheck()` ~lines 167-171

**Issue:** When the user clicks "Confirm Invoice" and `runViesPreCheck()` receives `valid=false` from VIES, the code immediately mutates the **Partner** record: `vat_status = NotRegistered`, `vat_number = null`. This is described in the spec as the correct behaviour — but it happens as an out-of-band side effect of an invoice action, without:
- A visible notification to the user that **another record** was just changed.
- A transactional boundary tying the partner mutation to the invoice confirmation (if the invoice then fails to confirm, the partner is already downgraded — and cannot be rolled back to `confirmed` without a fresh successful VIES roundtrip).
- An audit-trail entry explaining *why* the partner was downgraded (activity log exists, but doesn't capture the initiating invoice).

Legal and accounting implications:
- A downgrade can happen for transient VIES data issues (misconfigured MS feed, recent re-registration not yet in the DB). Art. 18(1)(b) CIR 282/2011 even contemplates "applied for but not yet issued" — the current code gives no route back to `confirmed` short of re-running the full VIES flow. That is acceptable, but the user must know.
- Silent auto-mutation of a business master record by a document action is surprising and makes issue reconstruction harder.

**Source of truth:** UX best practice; Art. 18 CIR 282/2011 (due-diligence reliance).

**Recommendation:**
1. On downgrade, fire a visible Filament notification: "Partner ‹X› has been marked as not VAT-registered because VIES rejected their number. This invoice has been re-scenario'd to ‹…›."
2. Extend the activity-log entry on Partner to record `{ reason: 'vies_invalid_at_invoice_confirmation', invoice_id: …, checked_at: … }` so the downgrade has a reconstructable story.
3. Leave the mutation in place — it is correct per spec. The issue is visibility, not logic.
4. Consider a "contest / re-check" action on the Partner view page that runs a fresh VIES call with `fresh: true` and, on valid, bumps the partner back to `confirmed`. This already exists ("Validate VAT" action) but is hidden for pending partners — extend visibility to partners downgraded from `confirmed` within the last 30 days.

**Status:** ✅ Resolved — shipped in partner-plan.md

---

## [F-009] "Confirm with Reverse Charge" override (VIES unavailable path) carries unmanaged legal risk
**Severity:** High
**Category:** Legal
**Affects:** `app/Services/CustomerInvoiceService.php` `confirmWithScenario()` when `ManualOverrideData` is passed; UI path documented in `spec.md:192-199` and `invoice-plan.md`.

**Issue:** The design lets a role-gated user opt in to reverse charge when VIES is **unavailable**, leveraging the partner's stored `vat_status = confirmed`. Audit fields are captured. This is defensible commercial practice, but the **legal safe harbour is thin**:

- Art. 18(1)(a) CIR 282/2011 requires the supplier to **obtain confirmation of validity** of the VAT number from VIES. "Stored confirmed status from a prior check" is not the confirmation *at the time of the supply* — it is historical evidence.
- CJEU case law (Euro Tyre, Mecsek-Gabona, *VSTR*, and C-247/21 Luxury Trust) accepts **good-faith reliance** + **due diligence** as a shield where objective evidence supports the 0% rating. But the shield requires evidence of due diligence for each supply.
- If НАП audits a reverse-charge invoice confirmed during a VIES outage and the partner's VAT has in fact lapsed, the tenant will be reassessed at the domestic rate. The audit trail (`reverse_charge_manual_override=true`, user, timestamp, reason) helps in a hearing but does not by itself restore the 0% rating.

The spec allows the override any time `partner.vat_status = confirmed`, regardless of how old that confirmation is. A 14-month-old "confirmed" stamp is worse than a recent one. Yet the UI treats them identically.

**Source of truth:**
- Art. 18(1)(a) CIR 282/2011
- CJEU C-273/11 *Mecsek-Gabona*, C-492/13 *Traum EOOD*, C-247/21 *Luxury Trust Automobil*

**Recommendation:**
1. **Gate the override on recency.** Only allow if `partner.vies_verified_at > now()->subDays(N)` where N = 30 or 60 (legal / risk call — ask the tenant's accountant for the N).
2. Require the user to tick an **acknowledgement** at the time of override: "I have obtained alternative proof of the customer's taxable status (e.g. VAT certificate) and will retain it for 10 years." Store the acknowledgement on the invoice (`reverse_charge_override_acknowledgement = true`).
3. Prefer **retry + wait** over override. Surface a stronger retry-loop UX before the override button becomes visible (e.g. require at least 3 retries across a 5-minute window; exponential backoff shown to the user).
4. Add a "Request documentation" prompt: when the override is used, queue a reminder for the accountant to verify the partner's VAT status via alternative proof within 7 days.
5. Expand the `ReverseChargeOverrideReason` enum once more reasons appear. For now only `vies_unavailable` exists — that is correct.

**Status:** ✅ Resolved — shipped in invoice-plan.md

---

## [F-010] Credit / debit note plans do not enforce the 5-day issuance window (чл. 115 ЗДДС)
**Severity:** Medium
**Category:** Legal · Accounting
**Affects:** `tasks/vat-vies/invoice-credit-debit.md`, `tasks/vat-vies/phase-c-plan.md`

**Issue:** BG law requires credit / debit notices (известие за дебит / кредит) to be issued **within 5 days of the event triggering the change** (price correction, discount, return, cancellation, etc.). The Phase C plan adds a confirmation modal, a PDF template, and scenario inheritance, but no constraint — soft or hard — on the interval between the triggering event and the note's `issued_at`. A user can comfortably issue a note dated "today" for an event that happened six weeks ago; the note is then formally late.

**Source of truth:**
- чл. 115, ал. 2 ЗДДС (5-day rule) — primary source verification pending against `lex.bg/laws/ldoc/2135533201` (paragraph numbering unverified — could be ал. 3 in current consolidated text).
- Directive 2006/112/EC Art. 219 (credit/debit notices treated as invoices) combined with each MS's invoice-issuance deadline under Art. 222.

**Recommendation:**
1. Add a `triggering_event_date` input to the credit/debit note form (date of return, price renegotiation, etc.). Defaults to `today` to preserve the current flow but makes the field explicit.
2. Warn the user at confirmation time if `issued_at - triggering_event_date > 5 days`: soft warning, not a hard block (the user may have business reasons to back-date; also BG allows late issuance with penalty rather than nullification).
3. Store `triggering_event_date` for audit. Don't surface it on the PDF unless the tenant's accountant asks.
4. Add a test: confirming a note where the gap > 5 days surfaces the warning.
5. Cross-check other MS. Not every MS has a 5-day rule — Germany is 6 months, France varies. Make the window configurable per tenant country.

**Status:** ✅ Resolved — shipped in invoice-credit-debit.md

---

## [F-011] Credit / debit note PDF references the parent invoice number but not the date
**Severity:** Medium
**Category:** Legal
**Affects:** `tasks/vat-vies/invoice-credit-debit.md`, `tasks/vat-vies/phase-c-plan.md`, PDF templates to be created (`resources/views/pdf/customer-credit-note.blade.php`, `resources/views/pdf/customer-debit-note.blade.php`)

**Issue:** Art. 219 requires the amending document to "**refer specifically and unambiguously to the initial invoice**." BG чл. 115 requires "указване на номера и датата на фактурата, към която е издадено." The current plan says "PDF templates reference parent invoice number"; no mention of the **date** of the original invoice. An invoice number alone is ambiguous if a tenant later has to renumber series (e.g. fiscal year rollover) — the date disambiguates.

**Source of truth:**
- Art. 219 Directive 2006/112/EC
- чл. 115 ЗДДС — specifically requires invoice number **and** date of the original invoice on the credit/debit note.

**Recommendation:**
Make the note PDF print `Referring to invoice: <number>, issued <date>, chargeable event <supplied_at if != issued_at>`. Include all three data points in the model's existing relations; no new schema needed — only template work.

**Status:** ✅ Resolved — shipped in pdf-rewrite.md

---

## [F-012] Sole-proprietor VAT numbers are GDPR personal data — no DSAR or erasure posture documented
**Severity:** Medium
**Category:** Security · Legal
**Affects:** Whole VAT/VIES feature — no file covers it

**Issue:** A VAT number of a **natural person acting as a taxable person** (sole proprietor, ЕТ in BG) is **personal data** under Regulation (EU) 2016/679 Art. 4(1). The app stores VAT numbers of partners and tenants; VIES lookups log the number + requester + timestamp on Commission servers. Requesting and storing these is lawful under GDPR Art. 6(1)(c) ("legal obligation"), but the controller obligations still apply:

1. **Retention** — the VAT number can be kept for as long as the underlying invoice is kept (BG: 10 years, ЗСч чл. 12). After that, deletion / anonymisation is required unless another legal obligation extends.
2. **DSAR / erasure requests** — a sole-proprietor partner can request confirmation of data held, rectification, or deletion (Arts. 15, 16, 17 GDPR). Erasure is restricted where legal obligation applies, but access and rectification are not.
3. **Purpose limitation / logging** — VIES lookups should be traceable (who looked up whom, when) to support Art. 30 GDPR processing records.

The plans contain none of this. For a pan-EU SaaS this is a blocker for launch in any MS with an active DPA.

**Source of truth:**
- Regulation (EU) 2016/679 Arts. 4(1), 6(1)(c), 15–17, 30
- EDPB Guidelines 05/2021 on the interplay between Art. 3 and the processing of data by EU-established controllers (general applicability)

**Recommendation:**
1. Add to `tasks/vat-vies/spec.md` a "**Privacy / GDPR**" section capturing:
   - Lawful basis for storing VAT numbers (legal obligation, Art. 6(1)(c) GDPR).
   - Retention window (invoice-linked retention + fiscal-period window; default 10 years from end of fiscal year per BG ЗСч чл. 12; document the MS variance).
   - Per-MS variance placeholder for ЕС tenant expansion.
2. Create a Partner-level "Data subject request" action that logs access requests (even if handling is manual for now).
3. Ensure VIES activity is logged with `{looked_up_vat, requester, invoice_id?, timestamp, request_identifier}` — the `LogsActivity` pattern used on Partner/CustomerInvoice already covers invoice-linked lookups; add it to standalone partner-form lookups.
4. Document that the tenant is the **data controller** for partner VAT data they store; the SaaS is the **processor**. DPA between tenants and operator should reference this.

**Status:** ✅ Resolved — shipped in pre-launch.md

---

## [F-013] VIES consultation number is not shown on the invoice PDF
**Severity:** Medium
**Category:** Legal · Accounting (best practice for audit)
**Affects:** `resources/views/pdf/customer-invoice.blade.php`; `customer_invoices.vies_request_id` is stored but not rendered

**Issue:** The Commission's *Your Europe* page on VIES recommends retaining the validation for tax control. The `requestIdentifier` (aka "consultation number") is the Commission-issued audit token proving that a specific VAT number was checked at a specific moment by a specific requester. Printing it on the invoice:
1. Proves due diligence at the moment the invoice was confirmed.
2. Allows НАП / any MS tax authority to verify the lookup independently.
3. Is common practice for EU B2B exemption invoices in the industry.

The plan stores the identifier but the PDF does not surface it.

**Source of truth:**
- Commission *Your Europe* — VIES validation retention recommendation.
- Art. 18(1)(a) CIR 282/2011 — supplier's duty to obtain confirmation.
- Industry practice (Odoo, SAP, Dynamics all render the VIES consultation number on EU B2B invoices).

**Recommendation:**
Add a one-line render to the PDF, conditional on `$invoice->is_reverse_charge && $invoice->vies_request_id`:
```
VIES consultation: <request_id> (checked <vies_checked_at>)
```
Under the "VAT Treatment" block. Cost: nil. Benefit: meaningful audit defence.

**Status:** Open

---

## [F-014] No OSS threshold warning **before** the threshold is exceeded
**Severity:** Medium
**Category:** UX · Accounting
**Affects:** `app/Models/EuOssAccumulation.php`, invoice form, company settings

**Issue:** The EUR 10 000 EU-wide OSS threshold (Art. 59c) flips a tenant from origin-taxation to destination-taxation **per calendar year**. Today the app reacts when the threshold is **already exceeded** (`EuOssAccumulation::isThresholdExceeded()` returns true → scenario switches to `EuB2cOverThreshold` and the user is suddenly charged a foreign VAT rate with no warning).

From the user's perspective, a single order can cross the threshold mid-invoice. Without advance warning the tenant can:
- fail to register for OSS in time (→ must retroactively register in the destination MS, or register for direct VAT in that MS);
- accidentally apply origin VAT to a supply that legally requires destination VAT;
- see unexpected price/total shifts on invoices.

**Source of truth:** Art. 59c, Title XII Ch. 6 Directive 2006/112/EC.

**Recommendation:**
1. Add a dashboard widget / Company Settings banner: "You are at EUR X / 10 000 of the OSS threshold this year." Yellow at 80%, red at 100%.
2. At invoice-form time, if the draft would push cumulative B2C intra-EU past the threshold, show a soft warning before submit.
3. Emit an event / notification when the threshold is first crossed (e-mail to the tenant's admin contact, Filament notification, audit entry).
4. Document the OSS registration timeline: a tenant who crosses the threshold must register for OSS **in the quarter of crossing** (or deregister the origin treatment; rules vary) — link to the tenant's MS OSS guidance.

**Status:** ✅ Resolved — shipped in pre-launch.md

---

## [F-015] Currency conversion rule is not documented (and BG ЗДДС post-Euro rule is not captured)
**Severity:** Medium
**Category:** Accounting · Legal
**Affects:** `customer_invoices.exchange_rate` (existing column) usage across `CustomerInvoiceService`, `EuOssService::convertTotalToEur()` (Phase B/C)

**Issue:** Plans mention `exchange_rate` as "existing" but nowhere specify:
- **Which** rate is used (BNB reference? ECB reference? customer-chosen?);
- **The date** to which the rate applies (chargeable-event date, issue date, or confirmation date);
- **The rounding rule** applied after conversion.

For BG post-Euro (2026-01-01), the Ministry of Finance / НАП aligns with Art. 91(2) Directive 2006/112/EC: either (a) the **BNB reference rate** on the chargeable-event date or (b) the **ECB published rate** on the chargeable-event date (option exercised once, notified to НАП). Mixing rates across documents in the same period is not allowed.

Conversion happens in at least two places in the current code paths:
- **Invoice total → EUR for OSS accumulation** (`EuOssService::accumulate()` / Phase C `adjust()`).
- **Foreign-currency invoice → EUR for tax-base determination** (if the invoice currency ≠ EUR).

Silent inconsistency between these two sites will produce audit-fail discrepancies.

**Source of truth:**
- Art. 91(2), Art. 230 Directive 2006/112/EC
- чл. 26 ЗДДС (tax base)
- ППЗДДС exchange-rate rule — likely чл. 55 in current consolidated text (verify against `lex.bg/laws/ldoc/2135534826`).

**Recommendation:**
1. Document the rule in `tasks/vat-vies/spec.md`: **"Exchange rate = BNB reference on the chargeable-event date, rounded to 5 decimals; tax base rounded to 2 decimals half-up."** Or the ECB equivalent — pick one.
2. Enforce at service layer: `CustomerInvoiceService` must populate `exchange_rate` from a single authoritative source (wire a `ForeignExchangeService` whose behaviour is testable / configurable per tenant country).
3. Add a regression test: same invoice, same chargeable-event date, different confirmation dates → identical `exchange_rate`, identical EUR total.
4. Per-MS variance is real — plan to make the rate-source strategy plug-in (BNB, ECB, HNB, MNB, etc.) once non-BG tenants onboard.
5. **Rounding rule**: document explicitly. BG requires half-up to the nearest cent; some MS use banker's rounding.

**Status:** ✅ Resolved — shipped in pre-launch.md

---

## [F-016] No documented retention policy for VAT records
**Severity:** Medium
**Category:** Legal · Security
**Affects:** Whole feature — no file covers retention

**Issue:** BG Law on Accounting (ЗСч) чл. 12 imposes a 10-year retention for accounting source documents (invoices, credit/debit notices, protocols, tax-period registers). BG ЗДДС чл. 121 ties retention to the tax-liability limitation period (effectively 5 years under ДОПК чл. 109). The **operative rule** for invoices is **10 years from the end of the fiscal year** in which the document was issued. The plans are silent on:
- Where records must be stored (checked-in Laravel DB is fine; BG ЗДДС чл. 114, ал. 6 requires "authenticity of origin, integrity of content, legibility" throughout retention);
- What happens on **tenant deletion** (per CLAUDE.md the app has a tenant lifecycle with deletion; legal retention may outlast tenancy);
- Whether electronic invoices are stored with integrity guarantees (cryptographic hash? append-only log?).

**Source of truth:**
- чл. 121 ЗДДС, чл. 114, ал. 6 ЗДДС (transposing Art. 233 Dir. 2006/112/EC)
- ЗСч чл. 12
- Art. 244, 247 Directive 2006/112/EC
- CLAUDE.md note on tenant lifecycle

**Recommendation:**
1. Add a "**Retention & integrity**" section to `tasks/vat-vies/spec.md`: 10 years from end of fiscal year, integrity via activity log + append-only invoice table + hash column.
2. Extend `CustomerInvoice` with a `document_hash` (SHA-256 of the rendered PDF + key fields) stored at confirmation; add an integrity-check command for audit.
3. Tenant deletion workflow must **preserve tax records** for the retention window even after the tenant deactivates — BG law on this side is clear: the controller is responsible for retention. Either: (a) block hard-delete until retention elapses; (b) export archive to tenant's accountant before hard-delete. Coordinate with the existing tenant lifecycle (CLAUDE.md reference).
4. Document that historical pre-Euro BGN invoices remain valid at their original values and need not be reissued (НАП guidance confirms).
5. Per-MS variance: confirm DE (10 years), FR (6 years + DAF 10 years for some records), IT (10 years), etc., when expanding. Make retention window configurable per tenant country.

**Status:** ✅ Resolved — shipped in pre-launch.md

---

## [F-017] Spec / implementation drift — VIES retry cooldown
**Severity:** Low
**Category:** UX · (doc hygiene)
**Affects:** `tasks/vat-vies/spec.md:196` (claims localStorage), `app/Services/CustomerInvoiceService.php:~133` (uses `partners.vies_last_checked_at`)

**Issue:** The spec says the 1-minute VIES retry cooldown is implemented client-side in `localStorage` keyed by invoice ID ("no server-side state needed"). The actual implementation uses a **server-side** field on the Partner (`vies_last_checked_at`). The server-side implementation is objectively better (tamper-resistant, shared across devices, doesn't reset on incognito / clear-storage). But the spec is wrong / stale.

**Source of truth:** N/A — this is spec/impl alignment.

**Recommendation:** Update `tasks/vat-vies/spec.md:196` to say "1-minute cooldown enforced at the service layer via `partners.vies_last_checked_at`; the UI just reads the server response to decide whether to show retry." Delete the localStorage sentence.

**Status:** ✅ Resolved — shipped in hotfix.md

---

## [F-018] `checkVatApprox` WSDL — plan flags uncertainty that is already resolved
**Severity:** Low
**Category:** Doc hygiene
**Affects:** `tasks/vat-vies/invoice-plan.md` (open question), `app/Services/ViesValidationService.php:48-49` (comment flags uncertainty)

**Issue:** The plan says: *"checkVatApprox may require a different WSDL endpoint (`checkVatApproxService.wsdl` instead of `checkVatService.wsdl`). Verify against the live VIES SOAP endpoint."* The live WSDL at `https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl` defines **both** `checkVat` and `checkVatApprox` operations in the same service description. One WSDL, two operations. The implementation uses the single WSDL correctly; the uncertainty is stale.

**Source of truth:** Live WSDL at `https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl` (inspected during review).

**Recommendation:**
1. Remove the "may require" comment in `ViesValidationService.php:48-49` (replace with a one-liner: "WSDL at checkVatService.wsdl exposes both operations").
2. Strike the open question from `invoice-plan.md`.

**Status:** ✅ Resolved — shipped in hotfix.md

---

## [F-019] `VatStatus::Pending` semantics are under-specified for non-invoice code paths
**Severity:** Medium
**Category:** Legal · UX
**Affects:** `VatStatus` enum, `Partner` model's `hasValidEuVat()` (returns true only for `Confirmed`), call sites in `VatScenario::determine()`

**Issue:** `Pending` means "VIES was unavailable at creation/last check; partner has a stored raw VAT number". The spec says pending partners are treated as non-VAT-registered on all invoices until confirmed. But `Pending` is **ambiguous** at every other call site:
- Does a `Pending` partner appear on reports filtered "has VAT number"? (Data-wise yes — raw number stored; semantically no — not confirmed.)
- Is a `Pending` partner's `vat_number` shown on a draft invoice preview? (If yes → UI falsely implies reverse charge will apply.)
- How long can a partner stay `Pending`? No SLA / alert. A partner pending for three months is either stale data or a VIES feed outage for that MS.

The **invoice scenario helper** already handles `Pending` defensively (treat as not confirmed). But the Partner master-data surface surrounding `Pending` is thin.

**Source of truth:** Spec, `partner.md:114-121`.

**Recommendation:**
1. Add a staleness watch: if `partner.vat_status = Pending` AND `vies_last_checked_at < now()->subDays(7)` → surface a widget on the Partner view: "VIES has been unavailable for this partner for 7+ days. Re-check now or escalate."
2. On reports / lists, display Pending partners with a clear visual marker (icon + tooltip) so no user confuses them with `Confirmed`.
3. Add a test: creating an invoice for a `Pending` partner never applies `EuB2bReverseCharge`.

**Status:** ✅ Resolved — shipped in partner-plan.md

---

## [F-020] 2026-01-01 SME / voluntary-non-registration regime (BG) not modelled
**Severity:** Medium
**Category:** Legal · Accounting
**Affects:** Whole feature — no file covers it

**Issue:** From **2026-01-01** BG has:
- A new **cross-border SME regime** (EU directive 2020/285, transposed into ЗДДС): taxable persons established in one MS and below the EU-wide annual EUR 100 000 turnover can apply the SME exemption in other MS too.
- New **mandatory registration threshold**: **EUR 51 130** taxable turnover (replacing the pre-Euro BGN 100 000).
- New **computation basis** for the threshold: **calendar year** (current OR previous), not rolling 12 months.
- New **7-day** application deadline from the day the threshold is exceeded.

None of this is captured in the plans. The implementation assumes a binary `is_vat_registered`. If the feature is meant to serve any BG SME from 2026-01-01 onwards, it needs:
- Automatic turnover tracking (sum of taxable sales per year per tenant).
- Threshold-crossing notification.
- Optional cross-border SME flag on the tenant (for tenants wanting to apply the SME exemption in other MS).

**Source of truth:**
- Directive (EU) 2020/285 (SME scheme, cross-border)
- чл. 96 ЗДДС (post-2026-01-01 amended text) — registration threshold + calendar-year basis
- НАП announcement "От 1 януари 2026 г. се въвежда специалния режим за малките предприятия"

**Recommendation:**
1. Add a taxable-turnover accumulator per tenant per calendar year (similar to `EuOssAccumulation` pattern but domestic).
2. Banner / notification when a non-registered tenant approaches the threshold (80% → yellow, 100% → red with "apply for VAT within 7 days" link).
3. Add a cross-border SME section to Company Settings; if enabled, participating MS list, per-MS turnover cap.
4. These are **new feature** work — flag for scheduling in a later phase.

**Status:** Open — future phase; not blocking but user must know **before** first tenant crosses the threshold unknowingly.

---

## [F-021] Credit-note scenario inheritance does not handle partner-status or tenant-status drift between parent invoice and note
**Severity:** Medium
**Category:** Legal · Accounting
**Affects:** `tasks/vat-vies/invoice-credit-debit.md`, `tasks/vat-vies/phase-c-plan.md`, `tasks/vat-vies/blocks-credit-debit.md`; `CustomerCreditNoteService`, `CustomerDebitNoteService` (to be modified)

**Issue:** Phase C says credit / debit notes **inherit** the parent invoice's `vat_scenario` and `is_reverse_charge`. This is accounting-correct: a correction must carry the same legal treatment as the original. But the plan assumes the parent's confirmation-time state is still legally valid at note time. Three edge cases need explicit handling:

1. **Partner VIES re-verification between parent and credit note.** Parent was `EuB2bReverseCharge` because `partner.vat_status = Confirmed`. Before the credit note is issued, VIES revokes → partner now `NotRegistered`. The credit note still carries reverse charge (correct — it mirrors the parent). But the **user** might re-run VIES on the credit-note form expecting it to auto-correct. The UI must make clear that the note inherits history, not the current partner state.

2. **Tenant VAT-registration change between parent and note.** Parent issued when tenant was VAT-registered; tenant later deregistered (threshold dropped, voluntary dereg); user wants to issue a credit note. The note legally must still carry the parent's VAT treatment (not Exempt). But `blocks-credit-debit.md` forces `Exempt` **unconditionally** when the tenant is non-registered — overriding parent inheritance. That is **wrong** for a corrective note of a prior VAT-registered supply.

3. **OSS-threshold crossing between parent and note.** Parent invoice used `EuB2cUnderThreshold`; after confirmation the tenant crossed the threshold; a credit note on that parent must still use `EuB2cUnderThreshold` (history). Phase C's `adjust()` uses `$invoice->issued_at->year` correctly for OSS adjustment. ✅ No change needed.

**Source of truth:** Art. 219 Directive 2006/112/EC (credit note equivalent to invoice, references original); Art. 90 (adjustments must mirror the original taxable basis); чл. 115 ЗДДС.

**Recommendation:**
1. Add a unit test: confirming a credit note for a parent invoice under a previous `is_vat_registered=true` tenant state — inheritance overrides the blocks-credit-debit `Exempt` force. Fix service logic if it currently forces unconditionally.
2. Update `blocks-credit-debit.md` to say: **inherit before blocking**; only apply the `Exempt` override if (a) the parent is **also** Exempt, or (b) the note is a standalone debit note (no parent).
3. On the credit-note form, render a read-only banner: "This note inherits the parent invoice's VAT treatment (‹scenario›). Current partner / tenant VAT status does not affect this note."
4. Add a refusal: if parent is not yet `Confirmed` (i.e. draft), block the note. Accounting correctness depends on the parent having a frozen treatment to mirror.

**Status:** ✅ Resolved — shipped in blocks-credit-debit.md

---

## [F-022] OSS coverage — verify TBE / electronic-services are treated alongside goods, not excluded
**Severity:** Medium
**Category:** Legal · Accounting
**Affects:** `app/Models/EuOssAccumulation.php`, `EuOssService`, `VatScenario::determine()`

**Issue:** The OSS Union scheme (Title XII, Ch. 6, Section 3 Directive 2006/112/EC) applies to:
1. Intra-EU **distance sales of goods** (Art. 33).
2. **All B2C services** supplied to consumers in other MS (including but not limited to TBE — telecommunications, broadcasting, electronically supplied services — per Art. 58).

The `VatScenario::determine()` logic does not distinguish goods vs services when deciding `EuB2cUnderThreshold` vs `EuB2cOverThreshold`. The EUR 10 000 threshold under Art. 59c applies to the **sum** of intra-EU distance-sale goods **and** TBE services combined. If the tenant sells both, both count toward the same ceiling. The plans do not discuss whether `EuOssAccumulation` includes service invoices; implementation needs verification.

Separately: some B2C services (immovable-property services, event admission, transport) have **special place-of-supply rules** not covered by OSS. A service invoice to a DE consumer for a BG hotel stay is taxable at the **event / property location** (Art. 47) → domestic BG VAT applies, **not** OSS. The current scenario model cannot express this; all services fall into the `EuB2c*` bucket.

**Source of truth:**
- Art. 33, 47–59 Directive 2006/112/EC
- Art. 59c — threshold applies to goods AND TBE combined
- чл. 14, чл. 21 ЗДДС

**Recommendation:**
1. Verify (via unit test) that `EuOssAccumulation::accumulate()` is called for both goods and service invoices in scope. If goods-only today — expand.
2. Document that the simple `EuB2c*` scenarios apply only to supplies where the EU distance-sale / TBE rules govern. Immovable-property, admission, passenger-transport services must be explicitly tagged at the product / service-category level and routed to `Domestic` (i.e. the place of performance), not `EuB2c*`.
3. Add a product/category flag: `service_place_of_supply = default | immovable_property | event_admission | passenger_transport | b2c_to_third_country_art_59`. Use it at scenario determination.
4. Until such tagging exists, render a warning on any service-line invoice to a cross-EU partner: "If this service is bound to immovable property, event admission, or passenger transport, re-check VAT treatment manually."

**Status:** ✅ Resolved — shipped in pre-launch.md

---

## [F-023] Tenant without an own VAT number gets no VIES `requestIdentifier` — audit trail is null for reverse-charge invoices
**Severity:** High
**Category:** Legal · Accounting
**Affects:** `app/Services/ViesValidationService.php:70-94`

**Issue:** `checkVatApprox` returns a `requestIdentifier` **only when the requester passes valid country code + VAT number**. The code defensively passes empty strings when the tenant's VAT is not set up (lines 83-87). In that branch, the returned `request_id` is `null`. If such a tenant then issues an `EuB2bReverseCharge` invoice, `customer_invoices.vies_request_id = null` — no audit token, no proof of due diligence.

The chain is improbable but reachable:
- Tenant marks `is_vat_registered=true` via the Company Settings form.
- VIES check succeeds → tenant's VAT number is stored.
- **But** the check itself was a `checkVatApprox` without tenant VAT requester info (at onboarding, before any VAT was stored).
- Subsequent partner checks use the now-stored tenant VAT → correct.

However, the current code reads `tenancy()->tenant?->vat_number` at each call. If the tenant's own VAT was cleared (voluntary dereg, country change), all subsequent partner checks will receive `request_id = null` silently.

**Source of truth:**
- Art. 18(1)(a) CIR 282/2011 — supplier must obtain confirmation.
- Art. 262 Directive / чл. 125 ЗДДС — recapitulative statement requires identification of acquirer VAT numbers but does not itself require `requestIdentifier` retention.
- Commission *Your Europe* page — recommends retaining the validation identifier.

**Recommendation:**
1. If the invoice scenario is `EuB2bReverseCharge` and `vies_request_id` on the confirmation response is null, **block confirmation** with a clear error: "Your tenant's VAT number is not configured. VIES cannot return a consultation number and the invoice cannot be issued under reverse charge." Offer a link to Company Settings to resolve.
2. At onboarding, prevent `is_vat_registered=true` from being set with a null `vat_number` (already enforced by invariant; good).
3. Add a test: confirming an EU B2B reverse-charge invoice with a tenant whose VAT number is null must fail.

**Status:** ✅ Resolved — shipped in tenant-plan.md

---

## [F-024] Invoice confirmation mutates the Partner record; transactional boundary must wrap both
**Severity:** Medium
**Category:** Security · Accounting
**Affects:** `app/Services/CustomerInvoiceService.php` (`runViesPreCheck()` mutates partner; `confirmWithScenario()` opens its own DB transaction)

**Issue:** Flow today (per audit):
1. User clicks Confirm.
2. `runViesPreCheck()` runs. On `invalid` VIES response → partner updated immediately (`vat_status = NotRegistered`, `vat_number = null`). **This mutation is not inside a DB transaction** tied to the invoice action.
3. User sees the modal with the new scenario, clicks Confirm.
4. `confirmWithScenario()` opens a transaction, writes the invoice, commits.

Problem: if step 3 is cancelled (user backs out) or step 4 fails (validation, concurrency), the partner mutation from step 2 **persists**. Subsequent operations with that partner are now downgraded, even though the user never confirmed the invoice.

Impact is not always legal (partner might legitimately be invalid), but it is a surprising side effect of a non-committed action and a data-integrity risk under concurrent confirms.

**Source of truth:** General DB-integrity / atomicity best practice; Laravel transaction guidance.

**Recommendation:**
1. Move the partner mutation **into** `confirmWithScenario()`'s transaction. On `invalid` VIES, store the intent in a DTO (`pendingPartnerDowngrade = true`); apply it inside the same transaction that writes the invoice.
2. If the invoice confirmation is cancelled / fails, the partner stays at its pre-check state. A re-run of the flow re-mutates.
3. If the user decides to proceed with "Confirm with VAT" (not reverse charge) after a VIES `invalid` — still downgrade the partner, because the partner's VAT number is objectively invalid. Just tie it to the invoice transaction.

**Status:** ✅ Resolved — shipped in invoice-plan.md

---

## [F-025] VIES-returned address is "best-effort parse" — data-quality risk when loaded into structured fields
**Severity:** Low
**Category:** UX · Data quality
**Affects:** `spec.md:62` ("VIES data is unstructured — best-effort parse"); Partner form and Tenant Company Settings pre-fill logic

**Issue:** VIES returns the partner's address as a **single unstructured string**, formatted differently by each MS feed (commas, newlines, postcode positions all vary). The spec acknowledges "best-effort parse". If the parse splits into structured street/city/postcode columns incorrectly, the invoice PDF renders a wrong address — which breaks Art. 226(5) (supplier/customer full address). A wrong-address invoice is **formally defective** (correctable, but annoying).

**Source of truth:** Art. 226(5) Directive 2006/112/EC; чл. 114, ал. 1, т. 4 ЗДДС.

**Recommendation:**
1. Store the **raw** VIES address string in a single `vies_raw_address` field **in addition to** the parsed structured fields. PDF falls back to raw when parse confidence is low.
2. Add a "VIES address confidence" flag per partner: `parsed | partial | raw_only`. UI warns the user on partial / raw_only.
3. Allow the user to manually override all address fields (already true — spec says "editable").
4. Add per-MS parser strategies when the pattern matters (DE uses "Street-Number, Postcode City"; FR uses newline-separated; etc.). Start with a generic parser; open stubs for MS-specific parsers.

**Status:** ✅ Resolved — shipped in partner-plan.md

---

## [F-026] Fiscal-printer (SUPTO) integration — VAT scenario must map to fiscal-receipt tax codes
**Severity:** Medium
**Category:** Legal · Accounting
**Affects:** Whole feature; cross-reference to `reference_fiscal_printer.md` (ErpNet.FP REST API for BG fiscal printers); `CustomerInvoiceService` at confirmation

**Issue:** BG ЗДДС + Наредба Н-18 impose **fiscal-receipt issuance** obligations for cash sales to consumers. The project is documented as a SUPTO ("Software for Management of Sales in Retail Outlets") app and has a fiscal-printer integration reference. Each fiscal receipt must carry the correct **tax group code** (A/B/Г/… depending on the rate and the operational type of the receipt).

The VAT scenario → fiscal-receipt tax group mapping is not documented in the VAT/VIES plans:
- `Domestic` 20% → tax group Б (20%).
- `Domestic` 9% → tax group В (9%).
- `Exempt`, `EuB2bReverseCharge`, `NonEuExport`, `EuB2cOverThreshold` (foreign rate) → tax group А (0%) or Г (nulled)?
- `EuB2cOverThreshold` applying e.g. a DE 19% rate → BG fiscal printer **cannot** print non-BG rates; the fiscal receipt is reported to BG NRA in BG tax groups only. The VAT on the fiscal receipt versus the invoice diverges.

This is a **legal issuance risk** for any BG tenant with a consumer-facing cross-border sale (rare, but not impossible — a BG online retailer selling to a DE buyer who picks up in-store).

**Source of truth:**
- Наредба Н-18/2006 — fiscal-receipt content and tax-group codes
- ЗДДС чл. 118 — fiscal device obligation
- Project reference: `reference_fiscal_printer.md`

**Recommendation:**
1. Document, in `tasks/vat-vies/spec.md`, the VAT scenario → BG tax-group mapping.
2. Flag consumer-facing `EuB2cOverThreshold` supplies as requiring a **non-fiscal** receipt (OSS goods sold cross-border do not go on a BG fiscal receipt; they are reported via OSS). Enforce at service layer: if payment is cash-at-till AND scenario is EU B2C Over Threshold → block the fiscal-receipt generation and route to OSS documentation.
3. Add an integration test (against the fiscal printer test harness if available) for each mapped combination.
4. Coordinate with the SUPTO team — this is out of the narrow VAT/VIES plan scope but owned by whoever wires the fiscal printer. Include a cross-link from the VAT/VIES spec to the fiscal-printer spec.

**Status:** Open — cross-team, flag to track.

---

## [F-027] No fallback path for Art. 18(1)(b) CIR 282/2011 ("VAT applied for but not yet issued")
**Severity:** Low
**Category:** Legal
**Affects:** Partner form (VIES flow); `VatStatus` enum

**Issue:** CIR 282/2011 Art. 18(1)(b) allows the supplier to treat a customer as a taxable person **even without a VAT number** if the customer informs the supplier that they have applied for one and the supplier performs "normal commercial security measures". This is a narrow but real path — most commonly for newly-formed businesses during the weeks between registration and first VAT-ID issuance.

The app has no expression of this state. `VatStatus` has `NotRegistered | Confirmed | Pending`. A "VAT-applied-but-not-yet-issued" partner falls under `NotRegistered` → the supplier cannot issue `EuB2bReverseCharge`, even though EU law permits it.

**Source of truth:** CIR 282/2011 Art. 18(1)(b).

**Recommendation:**
1. **Defer** — this is a low-volume edge case. For 99% of tenants the `NotRegistered` handling is correct. Flag as future-work item.
2. When scoped: add a fourth `VatStatus::PendingRegistration` (new business, doc of application obtained). Manual flag by the user; requires uploaded proof + supervisor role; no VIES automation.

**Status:** Open — future work, documented here for completeness.

---

## [F-028] Invoice 5-day issuance rule (чл. 113, ал. 4 ЗДДС) not enforced
**Severity:** High
**Category:** Legal · Accounting
**Affects:** `CustomerInvoiceService`, `CustomerInvoice` model, invoice form

**Issue:** Parallel to F-010 for credit/debit notes, BG law requires the **invoice itself** to be issued **within 5 days of the chargeable event** (чл. 113, ал. 4 ЗДДС — or, for prepayments, within 5 days of receipt of payment). The app has no concept of `supplied_at` separate from `issued_at` (see F-002 item 1), and no warning / block for late issuance. A user can confirm an invoice with `issued_at = today` for a supply delivered six weeks ago with no friction; the invoice is then formally late under BG law (penalty risk, not nullification — but it is a tax-authority audit flag).

**Source of truth:**
- чл. 113, ал. 4 ЗДДС
- Art. 222 Directive 2006/112/EC (MS-set issuance deadlines)

**Recommendation:**
1. Same fix shape as F-010: add `supplied_at` field + warn at confirmation when `issued_at - supplied_at > 5 days`.
2. Soft warning (tenant may have a defensible reason); store `supplied_at` for audit regardless.
3. Per-MS variance: BG 5 days, DE end of month following, FR end of month, IT 12 days, etc. Make the window a function of tenant country.
4. Regression test covers confirmation straddling the threshold.

**Status:** ✅ Resolved — shipped in pdf-rewrite.md

---

## [F-029] PDF document heading reads "INVOICE" but BG mandates "ФАКТУРА" (чл. 114, ал. 1, т. 1)
**Severity:** High
**Category:** Legal
**Affects:** `resources/views/pdf/customer-invoice.blade.php:108` — hard-coded `<div class="document-title">INVOICE</div>`

**Issue:** чл. 114, ал. 1, т. 1 ЗДДС requires the literal Bulgarian designation "**ФАКТУРА**" on a BG-issued invoice (and "ИЗВЕСТИЕ ЗА ДЕБИТ" / "ИЗВЕСТИЕ ЗА КРЕДИТ" / "ПРОТОКОЛ" for other document types). The PDF hard-codes the English "INVOICE". F-002 covers localization generically; this is the specific mandatory-label subpoint, separately citable.

For a BG tenant issuing to a BG customer, the PDF is **formally defective** on its face — independent of the other PDF gaps in F-002.

**Source of truth:**
- чл. 114, ал. 1, т. 1 ЗДДС
- Art. 226 Directive 2006/112/EC (no single MS-language requirement, but each MS prescribes the local heading)

**Recommendation:**
1. Localize the document heading by tenant country / invoicing locale: `BG → ФАКТУРА`, `EN → INVOICE`, `DE → RECHNUNG`, `FR → FACTURE`, etc.
2. Same treatment for credit notes (ИЗВЕСТИЕ ЗА КРЕДИТ), debit notes (ИЗВЕСТИЕ ЗА ДЕБИТ), and any protocols added later (ПРОТОКОЛ).
3. Ship the translation file alongside the F-002 fix for the "Reverse charge" wording.

**Status:** ✅ Resolved — shipped in pdf-rewrite.md

---

## [F-030] `VatScenario::determine()` routes empty `partner.country_code` to `NonEuExport` — silent 0% VAT on domestic sales (amplified by form design)
**Severity:** Critical
**Category:** Legal · Accounting
**Affects:** `app/Enums/VatScenario.php:42-44`; `app/Filament/Resources/Partners/Schemas/PartnerForm.php:49-55` (country_code is NOT `->required()` and options list is `EuCountries::forSelect()` — **EU countries only**)

**Issue:** The determination starts with:

```php
if (empty($partner->country_code)) {
    return self::NonEuExport;
}
```

`partner.country_code` is **nullable in the DB** (per migration audit) AND **not required on the Partner form**. Verified: the Partner form's country field is a `Select` without `->required()` and its options are **only EU countries** (`EuCountries::forSelect()`). This creates two failure modes:

1. **Silent under-declaration** — A BG user creates a domestic customer but forgets to pick BG in the country dropdown. The partner is saved with null `country_code`. On invoice confirmation, `determine()` returns `NonEuExport` → 0% VAT → the tenant **under-declares 20% VAT to НАП**.
2. **Semantic overloading of null** — Because the options list is EU-only, the app's **design** requires null `country_code` to represent "non-EU partner" (there is no non-EU option to pick). This makes it structurally impossible to disambiguate "forgot to set country" from "intentionally non-EU" — the code path is identical.

The user sees no error in either case. Invoices confirmed since Areas 1–3 shipped may include a mix of correct non-EU exports and incorrect under-declared domestic sales, all labelled `NonEuExport`.

This is not a plan gap; it is a **logic bug in already-shipped code** that can have been silently producing defective invoices since Areas 1–3 went live.

**Source of truth:**
- Art. 24 DOPK (tax obligation to declare correct tax)
- чл. 86, чл. 66 ЗДДС (VAT chargeability at BG domestic rate)
- Directive 2006/112/EC Arts. 193, 226

**Recommendation:**
1. **Expand the country-code options list to ALL countries** (ISO 3166-1 alpha-2) — EU + non-EU. Not just the EU subset. This makes country_code a reliable discriminator.
2. **Make `country_code` required** on the Partner form (`->required()` in Filament). Default to the tenant's country on partner creation (`->default(fn () => CompanySettings::get('company', 'country_code'))`).
3. **DB migration** — `ALTER TABLE partners ALTER COLUMN country_code SET NOT NULL` after a data-fix step that assigns a sensible default to existing null rows (candidate default = tenant country; escalate ambiguous rows to the tenant for manual resolution).
4. **Change `determine()`:** empty `country_code` must throw a `DomainException` (or return a new `Unknown` / `Undetermined` sentinel that `confirmWithScenario()` refuses to confirm). **Never** silently route to `NonEuExport`.
5. **Remediation query:** run a one-time audit across all tenants — `SELECT invoice_number, partner_id FROM customer_invoices WHERE vat_scenario = 'non_eu_export' AND partner.country_code IS NULL`. Each result is a candidate for credit-note + reissuance at the correct rate. **This is a tax-remediation exercise**, not just a code fix. Escalate to each tenant's accountant.
6. Regression test: confirming an invoice for a partner with null country must fail loudly; changing the form to pre-select tenant country must still allow user to override.

**Status:** ✅ Resolved — shipped in hotfix.md

---

## [F-031] Confirmed invoices have NO immutability guard (чл. 114, ал. 6 ЗДДС / Art. 233 Directive) — VERIFIED MISSING
**Severity:** Critical
**Category:** Legal · Security (integrity)
**Affects:** `app/Models/CustomerInvoice.php` — no `updating`/`deleting` guard found (grep returned zero matches)

**Issue:** Art. 233 Directive 2006/112/EC and чл. 114, ал. 6 ЗДДС require **authenticity of origin, integrity of content, and legibility** of invoices throughout retention. CLAUDE.md documents that `StockMovement` rows throw `RuntimeException` on update/delete — an intentional pattern. A confirmed `CustomerInvoice` warrants the same guard: once `status = Confirmed`, the document is a legal record and **must not be editable**. Deletion must go via a `CancelledInvoice` or credit-note flow, not a DB-level destroy.

**Verified:** a `Grep` for `RuntimeException|updating\(|deleting\(|protected static function booted` across `app/Models/CustomerInvoice.php` returned **zero matches**. The guard is absent. A developer / admin with any DB-write path (Tinker, Filament edit, raw migration) can edit or delete a Confirmed invoice in place, breaking the integrity-of-content requirement. The spec says the scenario is "frozen at confirmation", but "frozen field via comment" ≠ "immutable row via enforcement".

**Source of truth:**
- Art. 233 Directive 2006/112/EC
- чл. 114, ал. 6 ЗДДС
- ЗСч чл. 12 (integrity over retention)
- CLAUDE.md — StockMovement pattern

**Recommendation:**
1. Verify: grep `CustomerInvoice::boot()` / model events. If no immutability guard exists on Confirmed rows, add one modelled on `StockMovement`:
   ```php
   static::updating(function (self $invoice) {
       if ($invoice->getOriginal('status') === DocumentStatus::Confirmed) {
           throw new RuntimeException('Confirmed invoices are immutable — issue a credit note.');
       }
   });
   static::deleting(function (self $invoice) {
       if ($invoice->status === DocumentStatus::Confirmed) {
           throw new RuntimeException('Confirmed invoices cannot be deleted.');
       }
   });
   ```
2. Same guard on `CustomerInvoiceItem` rows belonging to confirmed invoices.
3. Same guard on confirmed credit / debit notes (Phase C).
4. Regression test: attempting to update a confirmed invoice throws.
5. Pair with F-016's `document_hash` for end-to-end integrity.

**Status:** ✅ Resolved — shipped in hotfix.md

---

## [F-032] Invoice number sequential / no-gaps guarantee (чл. 114, ал. 1, т. 2 ЗДДС) not verified
**Severity:** Medium
**Category:** Legal · Accounting
**Affects:** Invoice-numbering service (out of this review's read scope); `CustomerInvoice` model

**Issue:** BG requires the invoice number to be **10 digits, Arabic numerals, sequential, without gaps or repetitions** (чл. 114, ал. 1, т. 2 ЗДДС). If the numbering service allocates a number on draft creation and the user deletes the draft, a gap is created. If the number is allocated on confirmation only (correct pattern), concurrent confirmations must serialize to guarantee gap-free sequence. This review did not audit the numbering service.

**Source of truth:** чл. 114, ал. 1, т. 2 ЗДДС.

**Recommendation:**
1. Verify that invoice numbers are allocated **at confirmation**, not at draft creation, and that the allocation is serialized (row-lock or dedicated sequence table).
2. Format check: exactly 10 digits, zero-padded, tenant-scoped (each tenant has its own sequence).
3. Per-MS variance: BG is strict 10-digit; other MS allow alphanumeric prefixes per year/series. Parametrise once non-BG tenants onboard.
4. Regression test: delete a draft, confirm the next draft — numbers must be contiguous.

**Status:** ✅ Resolved — shipped in pre-launch.md

---

## [F-033] Advance payments / prepayments — chargeable event at receipt of payment not modelled
**Severity:** Medium
**Category:** Legal · Accounting
**Affects:** Whole invoice flow — no prepayment handling

**Issue:** Art. 65 Directive 2006/112/EC and чл. 25, ал. 7 ЗДДС establish a **second chargeable event**: the receipt of a payment on account (prepayment, deposit, advance). VAT becomes chargeable **at that moment**, on the received amount. The supplier must issue an invoice within 5 days of receipt (BG чл. 113, ал. 4).

The app has no `PrepaymentInvoice` document type and no branching in the confirmation flow for an advance-payment invoice. A tenant receiving a deposit on a large order has no supported path to issue the legally required advance-payment invoice.

**Source of truth:**
- Art. 65 Directive 2006/112/EC
- чл. 25, ал. 7 ЗДДС (chargeable event at receipt of payment)
- чл. 113, ал. 4 ЗДДС (5-day rule)

**Recommendation:**
1. Add a `PrepaymentInvoice` concept (or a flag `is_prepayment` on `CustomerInvoice`) with correct treatment: VAT charged on the received amount; final invoice at delivery nets the prepayment.
2. Until modelled, document explicitly in the spec that prepayments / deposits are **not supported** and advise tenants to issue those via their legacy flow or accountant.
3. Flag for a dedicated phase in the backlog.

**Status:** Open — future phase; flag so it is not accidentally assumed-supported.

---

## [F-034] Legacy `VAT-DETERMINATION-1` code status — VERIFIED GONE from code
**Severity:** Low
**Category:** Doc · Architectural hygiene
**Affects:** Memory note `project_vat_vies_design` claims "old VAT-DETERMINATION-1 code to be replaced"

**Issue:** The reviewer's own project memory records a pre-existing `VAT-DETERMINATION-1` module slated for replacement by the current VAT/VIES work.

**Verified:** `Grep` for `VAT-DETERMINATION-1|VatDetermination1|vat_determination_1` across the repo returned matches **only in planning / docs** (`tasks/backlog.md`, `tasks/phase-3.2-plan.md`, `tasks/phase-3.2-refactor.md`, `docs/STATUS.md`, this review file). **Zero matches in `app/` / `database/` / `resources/`.** Legacy code has been fully removed; no parallel-path bug exists.

**Source of truth:** Project memory + grep result.

**Recommendation:**
1. Remove the "to be replaced" memory note now that the replacement has shipped (housekeeping).
2. Optionally, add a one-line note in `tasks/vat-vies/spec.md`: "VAT-DETERMINATION-1 module was replaced by the VatScenario enum in Area 3, 2026-04-16."
3. Remaining plan-doc mentions are historical and safe.

**Status:** Resolved — memory cleanup only.

---

## [F-035] `blocks-credit-debit.md` references `applyExemptScenario()` with no definition
**Severity:** Low
**Category:** Doc hygiene · Planning
**Affects:** `tasks/vat-vies/blocks-credit-debit.md`

**Issue:** The file pseudo-code calls `applyExemptScenario($ccn)` but does not define the method's signature, target class (`CustomerCreditNoteService`? `CustomerDebitNoteService`?), or behaviour (does it mutate `vat_scenario`, `is_reverse_charge`, `vat_rate_id` on items, recalc totals, or all of the above?). A non-BG implementing agent reading this file in isolation cannot write the method without guessing.

**Recommendation:** Define the method contract in `blocks-credit-debit.md` (or better, consolidate it into `phase-c-plan.md` alongside the other credit/debit helper signatures). Single paragraph:

> `applyExemptScenario($note)` — protected helper on both `CustomerCreditNoteService` and `CustomerDebitNoteService`. Sets `$note->vat_scenario = VatScenario::Exempt`, `$note->is_reverse_charge = false`, re-applies the tenant's 0% zero-rate via `resolveZeroVatRate()` to all items, and recalculates `subtotal`, `tax_amount`, `total`. Called from `confirmWithScenario()` after inheritance when the tenant is non-VAT-registered **and** the parent is also Exempt (see F-021).

**Status:** ✅ Resolved — shipped in blocks-credit-debit.md

---

## [F-036] `invoice.md` ↔ `invoice-plan.md` drift on `$ignorePartnerVat` parameter
**Severity:** Low
**Category:** Doc hygiene
**Affects:** `tasks/vat-vies/invoice.md` (refactor section — says "Remove `$ignorePartnerVat` parameter"), `tasks/vat-vies/invoice-plan.md` Step 4 (says "Keep `$ignorePartnerVat` — still needed")

**Issue:** The two plan files disagree verbatim on whether the parameter is retained. Code keeps it (per audit of `VatScenario::determine()` at line 36). Docs need alignment.

**Recommendation:** Update both files to say "Kept — used by `confirm()` backward-compat wrapper and by the 'Confirm with VAT' path when VIES has rejected the partner." Resolve ambiguity in one place; cross-link from the other.

**Status:** ✅ Resolved — shipped in hotfix.md

---

