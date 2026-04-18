# VAT / VIES Feature — Master Spec

> Single source of truth for all design decisions across the entire VAT/VIES feature.
> All task files reference this document. Updated progressively as each area is discussed and agreed.
> Never contains implementation detail — that lives in `*-plan.md` files.
> **Audit reference:** `review.md` (27+ findings incorporated into the task / plan files below).

---

## Overview

### Core areas (original scope)

| # | Area | Task | Status |
|---|------|------|--------|
| 1 | Tenant company VAT setup | `tenant.md` | ✅ SHIPPED — refactor queue in `tenant-plan.md` |
| 2 | Partner VAT setup | `partner.md` | ✅ SHIPPED — refactor queue in `partner-plan.md` |
| 3 | Invoice VAT determination | `invoice.md` | ✅ SHIPPED — refactor queue in `invoice-plan.md` |
| 4 | Non-VAT-registered tenant blocks (invoice) | `blocks.md` | ✅ SHIPPED |

### Cross-cutting / derived tasks (added after 2026-04-17 review)

| Task | File | Depends on | Status |
|------|------|-----------|--------|
| Post-review hotfix (country_code, immutability, doc drift) | `hotfix.md` | — | ✅ SHIPPED |
| Legal references foundation (vat_legal_references table) | `legal-references.md` | hotfix landed | ✅ SHIPPED |
| Invoice PDF rewrite (Art. 226 compliance) | `pdf-rewrite.md` | legal-references | ✅ SHIPPED |
| DomesticExempt scenario (чл. 39–49 ЗДДС) | `domestic-exempt.md` | legal-references | ✅ SHIPPED |
| Credit / debit note VAT determination | `invoice-credit-debit.md` | pdf-rewrite, domestic-exempt | ✅ SHIPPED |
| Non-VAT-registered blocks — credit / debit notes | `blocks-credit-debit.md` | blocks, invoice-credit-debit | 📋 PLANNED |
| Pre-launch polish (GDPR, retention, FX, OSS warning, …) | `pre-launch.md` | all above | 📋 PLANNED |

### Recommended execution order

`hotfix` → `legal-references` → `pdf-rewrite` → `domestic-exempt` → `blocks` → `invoice-credit-debit` → `blocks-credit-debit` → done-task refactor plans (tenant/partner/invoice) → `pre-launch`.

---

## Legal Framework

- **EU VAT Directive 2006/112/EC** — governs VAT obligations across all EU member states
- **Article 196** — reverse charge mechanism for EU B2B cross-border supplies
- **Article 138** — exemption conditions for intra-EU supplies
- **VIES (VAT Information Exchange System)** — official EU service for VAT number validation; operated by the European Commission; member state databases updated by national tax authorities
- **Bulgarian ЗДДС** (Закон за данък върху добавената стойност) — local VAT law; НАП is the tax authority
- **Bulgarian EIK** — company registration number; Bulgarian VAT number = `BG` + EIK for most entities

---

## Shared Design Principles

These apply across all four areas. No exceptions without explicit agreement.

1. **VIES is the single source of truth** for VAT number validity — no manual entry, no overrides
2. **One flow for all EU countries** — no country-specific special-casing in UI logic
3. **No partial states** — `is_vat_registered = true` only exists alongside a VIES-confirmed VAT number, never without
4. **VIES unavailable = invalid** — service downtime is treated identically to an invalid response; no data stored
5. **VAT numbers in the DB come exclusively from VIES responses** — never from direct user input
6. **Placeholder field pattern** — read-only country code prefix + user input suffix; concatenated and sent to VIES; the placeholder field itself is never persisted
7. **Country change resets everything** — changing the country code clears the VAT field and resets `is_vat_registered` to false; a VAT number from one country cannot coexist with a different country on the same record

---

## Area 1: Tenant Company VAT Setup ✅ AGREED

### Context

Configured once during onboarding and revisitable in Company Settings. Highest legal risk of all four areas — a wrong VAT status on the tenant affects every invoice ever issued. VIES downtime during onboarding is acceptable because new tenants spend multiple hours on setup regardless.

### Fields

| Field | Type | DB-mapped | Notes |
|-------|------|-----------|-------|
| Country code | select | yes | Drives placeholder validation pattern; change resets VAT state |
| VAT lookup | placeholder | **no** | Read-only country code prefix + user input; used to call VIES only |
| Is VAT registered | toggle | yes | UI trigger only — never a direct DB write |
| VAT number | text (locked) | yes | Populated exclusively from VIES valid response; read-only in UI |
| Company name | text | yes | Pre-filled from VIES response; editable |
| Address | text/fields | yes | Pre-filled from VIES response; editable; VIES data is unstructured — best-effort parse |

### Rules

**Input behaviour:**
- Country selector change → clears toggle + VAT field
- Toggle ON → enables VIES check button; requires successful verification before form can be saved
- Toggle OFF → clears VAT field
- Placeholder field: country code prefix (read-only) + user input; pattern-validated per country

**VIES flow:**
- Concatenate country code + placeholder value → send to VIES
- **Valid** → store response VAT number in real (locked) field; pre-fill name + address (editable); `is_vat_registered = true`
- **Invalid** → reset toggle to false; clear VAT field; notify user
- **Unavailable** → same as invalid, no exceptions; notify "VIES service is unreachable — try again later"

**Save guards:**
- Toggle ON + no confirmed VAT number → form save blocked with validation error
- `is_vat_registered = true` in DB only ever coexists with a non-null VAT number

**Data guarantees:**
- `is_vat_registered = true` ↔ `vat_number IS NOT NULL` — invariant enforced at service layer, not just UI
- Placeholder field never touches the DB
- VAT number in DB comes exclusively from a VIES valid response

### Scope boundary

Non-VAT-registered tenant app-wide blocks are defined in this spec (Area 4) and implemented in a separate task (`blocks.md`). This task only covers the settings form and the data model.

---

## Area 2: Partner VAT Setup ✅ AGREED

### Context

Partners are created constantly during normal business operations — unlike tenant setup which happens once. VIES downtime is a real workflow concern here, so the unavailable state is handled differently from tenant.

### Fields

Same as Area 1 (tenant). All carry over:

| Field | Type | DB-mapped | Notes |
|-------|------|-----------|-------|
| Country code | select | yes | Change resets VAT state |
| VAT lookup | placeholder | **no** | Read-only country code prefix + user input; used to call VIES only |
| Is VAT registered | toggle | yes | UI trigger only — never a direct DB write |
| VAT number | text (locked) | yes | Populated exclusively from VIES valid response; read-only in UI |
| Company name | text | yes | Pre-filled from VIES response; editable |
| Address | text/fields | yes | Pre-filled from VIES response; editable |
| VAT verified at | timestamp | yes | Set on each successful VIES confirmation |
| VAT status | enum | yes | `not_registered` / `confirmed` / `pending` |

### VAT Status — Three States

| State | Meaning | Reverse charge eligible |
|-------|---------|------------------------|
| `not_registered` | Toggle off — partner is not VAT registered | No |
| `confirmed` | VIES returned valid — VAT number stored | Yes |
| `pending` | VIES was unavailable at creation/last check — VAT number not stored | No |

### Rules

**All tenant rules carry over:**
- Placeholder field pattern (read-only country prefix + user input)
- VAT number locked, populated from VIES response only
- Name + address pre-filled from VIES, editable
- Country change → reset toggle + clear VAT field
- Toggle OFF → clear VAT field
- VIES invalid → reset toggle, clear VAT field, status = `not_registered`
- Save guard: toggle ON + no confirmed VAT number → blocked

**Partner-specific delta:**
- **VIES unavailable ≠ invalid** — partner is saved with toggle ON but no VAT number; status = `pending`; user notified
- `pending` partners are treated as non-VAT-registered on all invoices until confirmed

### Re-verification

- **Manual** — always available; "Validate VAT" action on partner view page; runs full VIES flow; updates status + VAT number
- **At invoice confirmation** — re-check runs before the confirmation action fires; details and edge cases resolved in Area 3 (invoice) discussion
- **Periodic automatic** — out of scope

---

## Area 3: Invoice VAT Determination ✅ AGREED

### Context

Invoices are where VAT scenarios are legally applied and frozen. The treatment on a confirmed invoice is a legal fact — immutable after confirmation. VIES re-validation at confirmation time protects against reverse charge being applied to partners whose VAT registration has since lapsed.

### VAT Scenarios

Six scenarios (seven with `DomesticExempt` added in Phase B), applied in this priority order:

| Scenario | Condition | VAT Treatment |
|----------|-----------|---------------|
| Exempt | Tenant `is_vat_registered = false` | 0% VAT — чл. 113, ал. 9 ЗДДС |
| Domestic | Partner country = tenant country | Standard local VAT rate |
| DomesticExempt *(Phase B)* | Domestic partner + user-selected Art. 39–49 | 0% VAT — legal basis from `vat_legal_references` |
| EU B2B Reverse Charge | Different EU country + `vat_status = confirmed` (post-VIES re-check) | 0% VAT — Art. 138 (goods) or Art. 44 + 196 (services), Directive 2006/112/EC |
| EU B2C Under Threshold | Different EU country + no confirmed VAT + OSS threshold not exceeded | Tenant's domestic VAT rate |
| EU B2C Over Threshold | Different EU country + no confirmed VAT + OSS threshold exceeded | Destination country VAT rate |
| Non-EU Export | Non-EU country (see note below) | 0% VAT — Art. 146 (goods, export); Art. 44 (services — **outside scope of EU VAT**, not "exempt") |

> **Note on empty `country_code`:** null / empty country is **not** a valid scenario input. `VatScenario::determine()` throws a `DomainException` on empty country; the Partner form requires a country; the DB enforces NOT NULL. See `hotfix.md` / `[review.md#f-030]`.
>
> **Note on EU B2C services:** some services have special place-of-supply rules (immovable property Art. 47, event admission Art. 53/54, passenger transport Arts. 48–52, restaurant Art. 55) that override the `EuB2c*` default. Handling is deferred to a later phase — flag at product / service-category level. `[review.md#f-022]`
>
> **Note on goods vs services split:** `EuB2bReverseCharge` and `NonEuExport` both carry a `vat_scenario_sub_code` (`goods` | `services`) used at PDF render time to pick the correct article citation via `vat_legal_references`. See `legal-references.md`.

`DomesticExempt` is **never** auto-detected by `VatScenario::determine()` — the user explicitly toggles it on the draft form. The `vat_scenario_sub_code` column (added in Phase B migration) stores the specific article (`art_39`..`art_49`), legal reference resolved at PDF render time.

### VIES Re-check at Confirmation

Runs when: partner is in a different EU country AND `vat_status ∈ {confirmed, pending}`.

| VIES Response | Partner `vat_status` | Outcome |
|---------------|---------------------|---------|
| Valid | confirmed or pending | Partner: `vat_status = confirmed`, `vies_verified_at = now()`. Reverse charge applies. |
| Invalid | confirmed or pending | Partner: `vat_status = not_registered`, `vat_number` cleared. No reverse charge. No retry. |
| Unavailable | confirmed | Retry flow. Opt-in to reverse charge available (role-gated, audit trail). |
| Unavailable | pending | Charge VAT. No opt-in — no stored VAT number to stand behind. |

### Confirmation UI Flow

**Happy path (VIES responds):**

1. User clicks "Confirm Invoice" → VIES check runs → spinner on button
2. Modal opens with:
   - VAT scenario badge
   - Partner name + VAT number (if reverse charge)
   - VIES reference: `request_id` + timestamp
   - Financial summary: subtotal / VAT amount / total
   - **Cancel** — closes modal, invoice stays Draft, nothing saved
   - **Confirm** — writes to DB
3. On Confirm: scenario + VIES data stored, status → Confirmed

**VIES unavailable path:**

1. Modal does not open
2. View shows VIES error state with three elements:
   - **Retry button** — 1-minute cooldown enforced at the service layer via `partners.vies_last_checked_at` (server-side; tamper-resistant; shared across devices). UI reads the server response to decide whether to show "retry" or "wait". `[review.md#f-017]`
   - **"Confirm with VAT"** — always available to any user; no reverse charge; no special permission
   - **"Confirm with Reverse Charge"** — only shown when `partner.vat_status = confirmed`; role-gated; requires checkbox acknowledging responsibility; full audit trail stored on invoice. Future: recency gate + alt-proof acknowledgement `[review.md#f-009]`

**VIES invalid path:**

1. Modal does not open
2. Partner record updated immediately (`vat_status = not_registered`, `vat_number` cleared)
3. View reflects new scenario (reverse charge toggle off, scenario text updated)
4. User confirms normally — now as VAT scenario, no retry option

### Pricing Mode Constraint

When the determined VAT scenario is anything other than **Domestic**, pricing mode is forced to **VAT Exclusive** and the selector is disabled. This prevents net price distortion when VAT rates are overridden at confirmation time. Matches SAP, Dynamics, and Odoo behaviour.

### Data Stored on `customer_invoices`

| Column | Type | Notes |
|--------|------|-------|
| `vat_scenario` | enum | Frozen at confirmation — never changes after |
| `vies_request_id` | nullable string | From VIES SOAP response; null when unavailable |
| `vies_checked_at` | nullable timestamp | When the confirmation-time check was attempted |
| `vies_result` | enum: `valid` / `invalid` / `unavailable` | Result of that check |
| `reverse_charge_manual_override` | boolean | True when user opted into reverse charge despite VIES being unavailable |
| `reverse_charge_override_user_id` | nullable FK → users | Who approved the override |
| `reverse_charge_override_at` | nullable timestamp | When the override was approved |
| `reverse_charge_override_reason` | nullable enum | `vies_unavailable` — only value for now |

### Form Changes

**Partner select `helperText` (already exists, reactive):**
- Update to use `vat_status === confirmed` instead of `hasValidEuVat()`
- For `pending` partners: show warning + inline "Re-check VIES" button
- Successful re-check from the invoice form updates the partner record in DB

**`is_reverse_charge` toggle (already read-only/disabled):**
- Make reactive to partner selection — reflects expected scenario from stored partner data
- Controlled exclusively by VIES check result at confirmation; or by explicit opt-in (unavailable + confirmed case only)

**`pricing_mode` selector:**
- Disable and force to VAT Exclusive when partner triggers any non-Domestic scenario
- Already live-reactive to partner selection; just add the force logic

---

## Area 4: Non-VAT-Registered Tenant Blocks ✅ AGREED

### Context

When `is_vat_registered = false`, the tenant legally cannot charge VAT, apply reverse charge, or participate in OSS. This is a master switch that cascades across products, invoice forms, the confirmation flow, and PDFs. All outgoing document types are affected.

### VAT Scenario — 6th Case

`VatScenario::Exempt` is added to the enum. It is evaluated **first**, before any partner-based logic, and short-circuits the entire determination chain. Full scenario priority order:

| Priority | Value | Condition |
|----------|-------|-----------|
| 1 | `exempt` | Tenant `is_vat_registered = false` |
| 2 | `domestic` | Partner country = tenant country |
| 3 | `eu_b2b_reverse_charge` | Different EU country + `vat_status = confirmed` |
| 4 | `eu_b2c_under_threshold` | Different EU country + no confirmed VAT + below OSS threshold |
| 5 | `eu_b2c_over_threshold` | Different EU country + no confirmed VAT + above OSS threshold |
| 6 | `non_eu_export` | Non-EU country (empty country throws — see hotfix) |

### What Changes When `is_vat_registered = false`

**Products & Categories:**
- VAT rate field remains visible but options restricted to a single "0% — Exempt" rate
- No other rate selectable; field unlocks if tenant later registers

**Invoice form:**
- Pricing mode selector hidden entirely — irrelevant when all VAT is 0%; math is identical regardless of mode
- VAT rate on line items: forced to the 0% exempt rate; field not editable
- Partner select `helperText`: shows "Exempt — not VAT registered" regardless of partner country
- VIES re-check button for pending partners: not shown — no reverse charge possible
- `is_reverse_charge` toggle: not shown

**Confirmation flow:**
- VIES check does not run
- Scenario short-circuits to `VatScenario::Exempt`
- `is_reverse_charge` always `false`
- OSS accumulation (`EuOssService`) skipped
- Confirmation modal: scenario shown as "Exempt," no VAT breakdown, total = subtotal

**Invoice PDF:**
- No VAT breakdown section
- Legal notice rendered: **"чл. 113, ал. 9 ЗДДС"** (resolved from `vat_legal_references` table — see `legal-references.md`). This is **not** Art. 96 — Art. 96 is the registration-threshold rule and never appears on an invoice. `[review.md#f-004]`

### Pricing Mode

Hidden on all outgoing document forms when `is_vat_registered = false`. Stored value is ignored — all prices are treated as final amounts. No migration of existing values needed.

---

## Post-Phase E2E Bug Queue

Bugs found during manual browser testing that are deferred until all phases are complete. After the final phase ships, a dedicated E2E session will surface all remaining issues and this list will be worked through systematically.

| # | Found | Description | Severity |
|---|-------|-------------|----------|
| B-001 | 2026-04-18 | `CustomerInvoice` can be confirmed with zero line items — no guard at service or UI level. `CustomerInvoiceService::confirmWithScenario()` must validate `$invoice->items()->exists()` and throw (or return a blocking notification) before any other logic runs. Same guard should be applied to Credit Note and Debit Note confirmation. | High |
