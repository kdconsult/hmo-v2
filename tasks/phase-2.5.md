# Phase 2.5 — Hardening (Pre-Phase 3 Cleanup)

Items from post-Phase 2 brainstorming that must be done before starting Phase 3.
See `tasks/backlog.md` for full details on each item.

---

## Summary

| Task | Description | Status |
|------|-------------|--------|
| 2.5.1 | ProductStatus enum refactor | ✅ |
| 2.5.2 | Remove StockAdjustmentPage | ✅ |
| 2.5.3 | Display `moved_by` in StockMovementResource | ✅ |
| 2.5.4 | Generalize DocumentSeries → NumberSeries | ✅ |

---

## Task 2.5.1 — ProductStatus enum refactor [CATALOG-7]

Replace `is_active` boolean on `Product` with a `ProductStatus` enum.

- [x] Create `app/Enums/ProductStatus.php` — cases: `Draft`, `Active`, `Discontinued`
- [x] Migration: add `status` string column (default `active`), migrate data from `is_active`, drop `is_active`
- [x] `app/Models/Product.php` — replace `is_active` cast/fillable with `status`, update `scopeActive()`, update `getActivitylogOptions()`
- [x] `app/Filament/Resources/Products/Schemas/ProductForm.php` — replace `Toggle::make('is_active')` with `Select::make('status')`
- [x] `app/Filament/Resources/Products/Tables/ProductsTable.php` — replace `IconColumn::make('is_active')` with `TextColumn::make('status')->badge()`
- [x] `database/factories/ProductFactory.php` — replace `is_active` with `status`, update `inactive()` state
- [x] `tests/Feature/ProductCatalogTest.php` — update any tests referencing `is_active` on Product

**Behavior:**
- `Draft` — not yet available for use on documents
- `Active` — normal usage, shows in product selects
- `Discontinued` — hidden from new documents, preserved on historical records

---

## Task 2.5.2 — Remove StockAdjustmentPage [WAREHOUSE-1]

The page allows unrestricted stock manipulation with no authorization. Remove it.
The formal inventory audit (инвентаризация) is planned as WAREHOUSE-2 in the backlog.

- [x] Delete `app/Filament/Pages/StockAdjustmentPage.php`
- [x] Delete `resources/views/filament/pages/stock-adjustment-page.blade.php`
- [x] Update `docs/STATUS.md` — remove StockAdjustmentPage from file map and "What Works Today"
- [x] Update `docs/UI_PANELS.md` — remove StockAdjustmentPage

**Keep:** `StockService::adjust()`, `MovementType::Adjustment`, all permissions — will be used by future инвентаризация flow.

---

## Task 2.5.3 — Display `moved_by` in StockMovements [WAREHOUSE-5]

`moved_by` column and `Auth::id()` assignment already exist. What's missing: the relationship and UI display.

- [x] `app/Models/StockMovement.php` — add `movedBy()` BelongsTo relationship to User
- [x] `app/Filament/Resources/StockMovements/StockMovementResource.php` — add `TextColumn::make('movedBy.name')` column

---

## Task 2.5.4 — Generalize DocumentSeries → NumberSeries [CORE-1]

`DocumentSeries` is unused (Phase 3 not started). Rename now while cost is zero.

- [x] Create migration: rename table `document_series` → `number_series`, column `document_type` → `series_type`
- [x] `app/Enums/DocumentType.php` → `app/Enums/SeriesType.php` — add `Product`, `Partner` cases
- [x] `app/Models/DocumentSeries.php` → `app/Models/NumberSeries.php`
- [x] `app/Filament/Resources/DocumentSeries/` → `app/Filament/Resources/NumberSeries/` (all files)
- [x] `database/factories/DocumentSeriesFactory.php` → `database/factories/NumberSeriesFactory.php`
- [x] Update all references globally: `DocumentSeries`, `DocumentType`, `document_series`
- [x] Verify: `grep -r "DocumentSeries" app/ database/ tests/` → zero results (only historical migration remains)

---

## Verification

```bash
vendor/bin/pint --dirty --format agent
./vendor/bin/sail artisan test --compact
grep -r "DocumentSeries" app/ database/ tests/
grep -r "is_active" app/Models/Product.php
grep -r "StockAdjustmentPage" app/ resources/ tests/
```

All tests should pass. Three grep checks should return zero results.
