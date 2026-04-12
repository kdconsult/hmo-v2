# Phase 2 — Product Catalog + Warehouse/WMS

## Context

Phase 2 is the **first real tenant-side feature** of the HMO ERP. Phase 1 built the landlord panel, SaaS layer, tenancy infrastructure, and foundational tenant models (Partners, Contracts, VatRates, Currencies, DocumentSeries). Phase 2 builds the product catalog and warehouse/stock management that all future phases (Sales, Purchases, Field Service, Finance) depend on.

This plan establishes the patterns that every future implementing agent will reference. Getting it right matters.

**Starting point:** 232/232 tests passing. Clean git status on `main`.

---

## Confirmed Design Decisions

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | `decimal(15,4)` for catalog prices & stock quantities | 4dp for unit cost precision (1 screw = 0.0500 BGN); 15-digit width consistent with existing columns; future invoice totals use `decimal(15,2)` |
| 2 | **Always-variant pattern** — no polymorphic stockable | Every Product auto-gets a hidden "default" ProductVariant. Stock always tracked at variant level via simple FK. Simpler queries, simpler StockService |
| 3 | **Business-context MovementType** values | Purchase/Sale/TransferOut/TransferIn/Adjustment/Return/Opening/InitialStock — more descriptive than generic Receipt/Issue for audit trails |
| 4 | Keep `is_stockable` flag on Product | Default derived from type in model boot, but overridable for edge cases (office supplies not tracked in inventory) |
| 5 | Hidden auto-variant | User never sees variant UI for simple products. Default variant auto-created in model observer |
| 6 | Add `reference_type/reference_id` now (nullable) | Forward-looking for Phase 3+ Invoice/PO linking. No migration changes needed later |
| 7 | **Defer barcode scanning** (Task 2.7) | Barcode `varchar` field added for manual entry. Camera/BarcodeDetector UI deferred to later |
| 8 | SoftDeletes on Product, ProductVariant, Category, Warehouse, StockLocation | NOT on Unit (reference data), StockItem, StockMovement (audit integrity) |
| 9 | Adopt `NavigationGroup` enum | Phase 2 resources use enum. Migrate existing resources from plain strings |
| 10 | **Database-translatable** document-facing fields | `lara-zeus/spatie-translatable` v2.0 (Filament v5 compatible). Translatable: Product.name/description, Category.name/description, Unit.name, ProductVariant.name |
| 11 | Tenant-configured locales | Each tenant sets supported locales in CompanySettings. English always available as fallback |

---

## Dependencies to Install

```bash
composer require lara-zeus/spatie-translatable
# Pulls in spatie/laravel-translatable v6.13 automatically
```

---

## Implementation Steps

### Step 1 — Enums

**1a. NEW: `app/Enums/ProductType.php`** (replaces scaffold `NomenclatureType`)

```
BackedEnum (string), implements HasColor, HasLabel
Cases:
  Stock   = 'stock'    → label: __('Stock Item'),  color: 'primary'
  Service = 'service'  → label: __('Service'),     color: 'success'
  Bundle  = 'bundle'   → label: __('Bundle'),      color: 'warning'
```

**1b. NEW: `app/Enums/UnitType.php`**

```
BackedEnum (string), implements HasLabel
Cases: Mass, Volume, Length, Area, Time, Piece, Other
Values: mass, volume, length, area, time, piece, other
```

**1c. MODIFY: `app/Enums/MovementType.php`**

- ADD case: `Opening = 'opening'` → label: `__('Opening')`, color: success group
- REMOVE cases: `InternalConsumption`, `Production` (scaffold, not Phase 2)
- Final cases: Purchase, Sale, TransferOut, TransferIn, Adjustment, Return, Opening, InitialStock

**1d. DELETE: `app/Enums/NomenclatureType.php`**

- First verify no references: `grep -r "NomenclatureType" app/ tests/ database/ config/`
- Replace any found references with `ProductType`

---

### Step 2 — Migrations (all in `database/migrations/tenant/`)

**2a. `create_categories_table`**

```php
$table->id();
$table->json('name');                    // translatable
$table->string('slug')->unique();
$table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
$table->json('description')->nullable(); // translatable
$table->boolean('is_active')->default(true);
$table->timestamps();
$table->softDeletes();
$table->index(['parent_id']);
$table->index('is_active');
```

**2b. `create_units_table`**

```php
$table->id();
$table->json('name');                    // translatable
$table->string('symbol', 20);
$table->string('type');                  // UnitType enum
$table->boolean('is_active')->default(true);
$table->timestamps();
$table->index('type');
```

No SoftDeletes.

**2c. `create_products_table`**

```php
$table->id();
$table->string('code')->unique();
$table->json('name');                    // translatable
$table->json('description')->nullable(); // translatable
$table->string('type');                  // ProductType enum
$table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
$table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
$table->decimal('purchase_price', 15, 4)->nullable();
$table->decimal('sale_price', 15, 4)->nullable();
$table->foreignId('vat_rate_id')->nullable()->constrained('vat_rates')->nullOnDelete();
$table->boolean('is_active')->default(true);
$table->boolean('is_stockable')->default(true);
$table->string('barcode', 128)->nullable();
$table->json('attributes')->nullable();
$table->timestamps();
$table->softDeletes();
$table->index(['type', 'is_active']);
$table->index('category_id');
$table->index('barcode');
```

**2d. `create_product_variants_table`**

```php
$table->id();
$table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
$table->json('name');                    // translatable
$table->string('sku')->unique();
$table->decimal('purchase_price', 15, 4)->nullable();
$table->decimal('sale_price', 15, 4)->nullable();
$table->string('barcode', 128)->nullable();
$table->boolean('is_default')->default(false);
$table->boolean('is_active')->default(true);
$table->json('attributes')->nullable();
$table->timestamps();
$table->softDeletes();
$table->index(['product_id', 'is_default']);
$table->index('barcode');
```

**2e. `create_warehouses_table`**

```php
$table->id();
$table->string('name');
$table->string('code')->unique();
$table->json('address')->nullable();     // {street, city, postal_code, country}
$table->boolean('is_active')->default(true);
$table->boolean('is_default')->default(false);
$table->timestamps();
$table->softDeletes();
$table->index('is_active');
```

**2f. `create_stock_locations_table`**

```php
$table->id();
$table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
$table->string('name');
$table->string('code');
$table->boolean('is_active')->default(true);
$table->timestamps();
$table->softDeletes();
$table->unique(['warehouse_id', 'code']);
```

**2g. `create_stock_items_table`**

```php
$table->id();
$table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
$table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
$table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
$table->decimal('quantity', 15, 4)->default(0);
$table->decimal('reserved_quantity', 15, 4)->default(0);
$table->timestamps();
```

After `Schema::create()`, add PostgreSQL partial unique index for NULL handling:

```php
DB::statement('CREATE UNIQUE INDEX stock_items_variant_warehouse_location_unique
    ON stock_items (product_variant_id, warehouse_id, COALESCE(stock_location_id, 0))');
```

No SoftDeletes. No delete allowed.

**2h. `create_stock_movements_table`**

```php
$table->id();
$table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
$table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
$table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
$table->string('type');                  // MovementType enum
$table->decimal('quantity', 15, 4);      // signed: positive=in, negative=out
$table->nullableMorphs('reference');     // reference_type, reference_id for future Invoice/PO
$table->text('notes')->nullable();
$table->timestamp('moved_at')->useCurrent();
$table->unsignedBigInteger('moved_by')->nullable(); // User.id, no FK (cross-database)
$table->timestamps();
$table->index('type');
$table->index('moved_at');
$table->index(['reference_type', 'reference_id']);
```

No SoftDeletes. Immutable.

---

### Step 3 — Models

All tenant models. No `CentralConnection` trait. No `tenant_id` column.

**3a. `app/Models/Category.php`**

```
Traits: HasFactory, HasTranslations (spatie), SoftDeletes
Translatable: ['name', 'description']
Fillable: name, slug, parent_id, description, is_active
Casts: is_active → boolean

Relationships:
  parent(): BelongsTo(Category, 'parent_id')
  children(): HasMany(Category, 'parent_id')
  products(): HasMany(Product)

Scopes: scopeRoots(whereNull parent_id), scopeActive, scopeWithChildren

Boot (saving event):
  - Auto-generate slug from name (Str::slug) if not set or name changed
  - Depth validation: walk parent chain. If depth > 2 (0=root, 1=child, 2=grandchild), throw InvalidArgumentException
  - Also prevent re-parenting that would push descendants beyond depth 2

Helper: depthLevel(): int — walks parent chain and returns 0-indexed depth
```

**3b. `app/Models/Unit.php`**

```
Traits: HasFactory, HasTranslations (spatie)
Translatable: ['name']
Fillable: name, symbol, type, is_active
Casts: type → UnitType::class, is_active → boolean
Scopes: scopeActive
Relationships: products(): HasMany(Product)
```

No SoftDeletes.

**3c. `app/Models/Product.php`**

```
Traits: HasFactory, HasTranslations (spatie), SoftDeletes, LogsActivity (Spatie Activitylog)
Translatable: ['name', 'description']
Fillable: code, name, description, type, category_id, unit_id, purchase_price, sale_price,
          vat_rate_id, is_active, is_stockable, barcode, attributes
Casts: type → ProductType, purchase_price → 'decimal:4', sale_price → 'decimal:4',
       is_active → boolean, is_stockable → boolean, attributes → array

ActivityLog: logOnly(['name', 'code', 'type', 'is_active', 'sale_price']), logOnlyDirty

Relationships:
  category(): BelongsTo(Category)
  unit(): BelongsTo(Unit)
  vatRate(): BelongsTo(VatRate)
  variants(): HasMany(ProductVariant)
  defaultVariant(): HasOne(ProductVariant)->where('is_default', true)

Scopes: scopeActive

Boot:
  static::creating → set is_stockable from type if not explicitly dirty
    Service → false, Stock/Bundle → true
  static::created → auto-create default ProductVariant:
    $model->variants()->create([
      'name' => $model->name,
      'sku'  => $model->code,
      'is_default' => true,
      'is_active'  => true,
    ])

Helper: hasVariants(): bool — variants()->where('is_default', false)->where('is_active', true)->exists()
```

**3d. `app/Models/ProductVariant.php`**

```
Traits: HasFactory, HasTranslations (spatie), SoftDeletes
Translatable: ['name']
Fillable: product_id, name, sku, purchase_price, sale_price, barcode, is_default, is_active, attributes
Casts: purchase_price → 'decimal:4', sale_price → 'decimal:4',
       is_default → boolean, is_active → boolean, attributes → array

Relationships:
  product(): BelongsTo(Product)
  stockItems(): HasMany(StockItem)
  stockMovements(): HasMany(StockMovement)

Helpers:
  effectivePurchasePrice(): ?string → $this->purchase_price ?? $this->product->purchase_price
  effectiveSalePrice(): ?string → $this->sale_price ?? $this->product->sale_price
```

**3e. `app/Models/Warehouse.php`**

```
Traits: HasFactory, SoftDeletes
Fillable: name, code, address, is_active, is_default
Casts: address → array, is_active → boolean, is_default → boolean

Relationships:
  stockLocations(): HasMany(StockLocation)
  stockItems(): HasMany(StockItem)
  stockMovements(): HasMany(StockMovement)

Scopes: scopeActive

Boot (saving): enforce single is_default per tenant
  if ($model->is_default && $model->isDirty('is_default')) {
    Warehouse::where('id', '!=', $model->id ?? 0)->where('is_default', true)->update(['is_default' => false]);
  }
```

Not translatable (internal, not document-facing).

**3f. `app/Models/StockLocation.php`**

```
Traits: HasFactory, SoftDeletes
Fillable: warehouse_id, name, code, is_active
Casts: is_active → boolean
Relationships: warehouse(): BelongsTo(Warehouse), stockItems(): HasMany(StockItem)
Scopes: scopeActive
```

**3g. `app/Models/StockItem.php`**

```
Traits: HasFactory
Fillable: product_variant_id, warehouse_id, stock_location_id, quantity, reserved_quantity
Casts: quantity → 'decimal:4', reserved_quantity → 'decimal:4'

Relationships:
  productVariant(): BelongsTo(ProductVariant)
  warehouse(): BelongsTo(Warehouse)
  stockLocation(): BelongsTo(StockLocation)

Accessor: getAvailableQuantityAttribute() → (float)$this->quantity - (float)$this->reserved_quantity
```

No SoftDeletes, no delete allowed.

**3h. `app/Models/StockMovement.php`**

```
Traits: HasFactory
Fillable: product_variant_id, warehouse_id, stock_location_id, type, quantity,
          reference_type, reference_id, notes, moved_at, moved_by
Casts: type → MovementType, quantity → 'decimal:4', moved_at → datetime

Relationships:
  productVariant(): BelongsTo(ProductVariant)
  warehouse(): BelongsTo(Warehouse)
  stockLocation(): BelongsTo(StockLocation)
  reference(): MorphTo (nullable)

Boot: Immutability enforcement
  static::updating → throw RuntimeException('Stock movements are immutable.')
  static::deleting → throw RuntimeException('Stock movements are immutable.')
```

---

### Step 4 — Morph Map + Exception

**4a. MODIFY: `app/Providers/AppServiceProvider.php`**

Add to `Relation::morphMap()`:
```php
'product' => Product::class,
'product_variant' => ProductVariant::class,
'warehouse' => Warehouse::class,
'stock_movement' => StockMovement::class,
```

**4b. NEW: `app/Exceptions/InsufficientStockException.php`**

First custom exception in the app. Extends `RuntimeException`.

```php
public function __construct(
    public readonly ProductVariant $productVariant,
    public readonly Warehouse $warehouse,
    public readonly string $requestedQuantity,
    public readonly string $availableQuantity,
) {
    parent::__construct(sprintf(
        'Insufficient stock for "%s" (SKU: %s) in warehouse "%s". Requested: %s, Available: %s.',
        $productVariant->name, $productVariant->sku, $warehouse->name,
        $requestedQuantity, $availableQuantity,
    ));
}
```

---

### Step 5 — StockService

**NEW: `app/Services/StockService.php`**

Stateless service. All methods wrapped in `DB::transaction()`. Uses `bcmath` for decimal arithmetic (4dp precision).

```
receive(ProductVariant, Warehouse, qty, ?StockLocation, ?Model reference, MovementType = Purchase)
  → findOrCreateStockItem, increment quantity, create Movement(positive qty)
  → returns StockItem

issue(ProductVariant, Warehouse, qty, ?StockLocation, ?Model reference, MovementType = Sale)
  → check available_quantity >= requested (bccomp)
  → throws InsufficientStockException if insufficient
  → decrement quantity, create Movement(negative qty)
  → returns StockItem

adjust(ProductVariant, Warehouse, qty (signed), reason, ?StockLocation)
  → increment or decrement based on sign
  → create Movement(Adjustment type, signed qty, notes = reason)
  → returns StockItem

transfer(ProductVariant, fromWarehouse, toWarehouse, qty, ?fromLocation, ?toLocation)
  → check source available_quantity (throws InsufficientStockException)
  → decrement source, create Movement(TransferOut, negative)
  → increment destination, create Movement(TransferIn, positive)
  → returns [fromStockItem, toStockItem]

Private helpers:
  findOrCreateStockItem(variant, warehouse, ?location) → StockItem::firstOrCreate
  createMovement(variant, warehouse, ?location, type, qty, ?reference, ?notes) → StockMovement::create with moved_by = auth()->id()
```

---

### Step 6 — Factories

All in `database/factories/`. Follow existing pattern.

| Factory | Model | Key States |
|---------|-------|------------|
| `CategoryFactory` | Category | `root()`, `child()` (creates parent), `grandchild()` (creates parent chain), `inactive()` |
| `UnitFactory` | Unit | Default: type=Piece, random name/symbol |
| `ProductFactory` | Product | `stock()`, `service()` (is_stockable=false), `bundle()`, `inactive()` |
| `ProductVariantFactory` | ProductVariant | `default()` (is_default=true). Default product_id: Product::factory() |
| `WarehouseFactory` | Warehouse | `default()` (is_default=true). Includes address JSON |
| `StockLocationFactory` | StockLocation | Default warehouse_id: Warehouse::factory() |
| `StockItemFactory` | StockItem | Default: random quantity, zero reserved |
| `StockMovementFactory` | StockMovement | Default: type=Purchase, positive quantity |

---

### Step 7 — Seeders & TenantOnboardingService

**7a. NEW: `database/seeders/UnitSeeder.php`**

Seeds 13 standard units using `updateOrCreate` keyed on `symbol`. Uses `setTranslation()` for English names (Bulgarian translations can be added later).

```
pcs (Piece), kg (Mass), g (Mass), t (Mass), l (Volume), ml (Volume),
m (Length), cm (Length), mm (Length), m² (Area), h (Time), day (Time), month (Time)
```

**7b. MODIFY: `app/Services/TenantOnboardingService.php`**

Add after existing seeders in `onboard()`:
1. `$this->runSeeder(UnitSeeder::class);`
2. Create default warehouse:
   ```php
   Warehouse::firstOrCreate(
       ['code' => 'MAIN'],
       ['name' => 'Main Warehouse', 'is_default' => true, 'is_active' => true]
   );
   ```
3. Set default supported locales in CompanySettings:
   ```php
   CompanySettings::set('localization', 'supported_locales', json_encode(['en']));
   CompanySettings::set('localization', 'default_locale', 'en');
   ```

---

### Step 8 — RBAC

**MODIFY: `database/seeders/RolesAndPermissionsSeeder.php`**

Add to `$models` array:
```php
'category', 'unit', 'product', 'product_variant',
'warehouse', 'stock_location', 'stock_item', 'stock_movement',
```

This auto-generates 40 new permissions (5 actions × 8 models).

Role updates:

| Role | New Permissions |
|------|-----------------|
| `admin` | All (automatic via `$allPermissions`) |
| `warehouse-manager` | Full CRUD: warehouse, stock_location, stock_movement. Create+update: stock_item. View-only: product, product_variant, category, unit |
| `sales-manager` | Add view_any/view for: product, product_variant, category, unit, stock_item |
| `viewer` | All view_* (automatic via filter) |
| `finance-manager` | All view_* (automatic via filter) |

---

### Step 9 — Policies

All in `app/Policies/`. All follow the **exact same pattern** as existing `PartnerPolicy.php`.

| Policy | Permission Prefix | Special Rules |
|--------|-------------------|---------------|
| `CategoryPolicy` | `category` | Standard 7 methods |
| `UnitPolicy` | `unit` | Standard 7 methods |
| `ProductPolicy` | `product` | Standard 7 methods |
| `ProductVariantPolicy` | `product_variant` | Standard 7 methods |
| `WarehousePolicy` | `warehouse` | Standard 7 methods |
| `StockLocationPolicy` | `stock_location` | Standard 7 methods |
| `StockItemPolicy` | `stock_item` | delete/forceDelete/restore always return `false` |
| `StockMovementPolicy` | `stock_movement` | update/delete/forceDelete/restore always return `false` |

---

### Step 10 — Translatable Plugin Setup

**10a. MODIFY: `app/Providers/Filament/AdminPanelProvider.php`**

Register the translatable plugin:
```php
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;

->plugin(
    SpatieTranslatablePlugin::make()
        ->defaultLocales(['en'])
        ->persist(),
)
```

**10b. Consider creating a reusable trait** or base resource concern that reads tenant locales from CompanySettings for `getTranslatableLocales()`. Resources that have translatable fields will use this to dynamically provide the tenant's configured locales.

---

### Step 11 — Filament Resources: Catalog Group

All use `NavigationGroup::Catalog`. All follow the directory-per-resource pattern from `PartnerResource`.

**11a. CategoryResource** — `app/Filament/Resources/Categories/`

Structure: `CategoryResource.php`, `Schemas/CategoryForm.php`, `Schemas/CategoryInfolist.php`, `Tables/CategoriesTable.php`, `Pages/{List,Create,Edit,View}Category.php`

- Resource: `Translatable` trait from lara-zeus, `NavigationGroup::Catalog`, icon: `OutlinedRectangleStack`
- All Pages: respective `Translatable` trait + `LocaleSwitcher::make()` in header actions
- Form: name (TextInput, required), slug (TextInput, disabled on edit, auto-generated), parent_id (Select, filtered to exclude self + descendants, only depth 0-1 allowed as parents), description (Textarea), is_active (Toggle)
- Table: name, parent.name (placeholder 'Root'), is_active (IconColumn), TrashedFilter
- SoftDeletingScope removal in `getRecordRouteBindingEloquentQuery()`

**11b. UnitResource** — `app/Filament/Resources/Units/`

Simple ManageRecords pattern (like TagResource). Single page.

Structure: `UnitResource.php`, `Pages/ManageUnits.php`

- Resource: `Translatable` trait, `NavigationGroup::Catalog`, icon: `OutlinedScale`
- ManageUnits: `Translatable` trait + `LocaleSwitcher::make()` + `CreateAction::make()` in header
- Inline form: name (TextInput), symbol (TextInput), type (Select from UnitType), is_active (Toggle)
- Inline table: name, symbol, type (badge), is_active (IconColumn), SelectFilter(type), TernaryFilter(is_active)

**11c. ProductResource** — `app/Filament/Resources/Products/`

Structure: `ProductResource.php`, `Schemas/ProductForm.php`, `Schemas/ProductInfolist.php`, `Tables/ProductsTable.php`, `Pages/{List,Create,Edit,View}Product.php`, `RelationManagers/ProductVariantsRelationManager.php`

- Resource: `Translatable` trait, `NavigationGroup::Catalog`, icon: `OutlinedCube`, `recordTitleAttribute: 'name'`
- All Pages: respective `Translatable` trait + `LocaleSwitcher::make()`
- Form sections:
  - **General**: code (TextInput, required, unique ignoreRecord), name (TextInput, required), type (Select ProductType, required, live), category_id (Select), unit_id (Select), description (Textarea)
  - **Pricing**: purchase_price (TextInput numeric), sale_price (TextInput numeric), vat_rate_id (Select from VatRate::active())
  - **Settings**: is_stockable (Toggle, reactive — auto-set from type via afterStateUpdated), is_active (Toggle), barcode (TextInput, maxLength 128)
- Table: code, name, type (badge), category.name, sale_price (numeric 2dp), is_active (IconColumn). Filters: type, category, is_active, TrashedFilter
- ProductVariantsRelationManager:
  - `Translatable` trait + `#[Reactive] public ?string $activeLocale = null`
  - `$relationship = 'variants'`
  - `modifyQueryUsing`: filter out `is_default = true` (hide default variant)
  - Form: name, sku (unique ignoreRecord), purchase_price, sale_price, barcode, is_active
  - Table: name, sku, sale_price, is_active
  - SoftDeletingScope removal in `getRecordRouteBindingEloquentQuery()`

---

### Step 12 — Filament Resources: Warehouse Group

All use `NavigationGroup::Warehouse`. Warehouse/StockLocation resources are **not** translatable (internal, not document-facing).

**12a. WarehouseResource** — `app/Filament/Resources/Warehouses/`

Structure: Full directory pattern with `RelationManagers/StockLocationsRelationManager.php`

- `NavigationGroup::Warehouse`, icon: `OutlinedBuildingStorefront`
- Form sections:
  - **General**: name, code (unique ignoreRecord), is_active, is_default
  - **Address**: address.street, address.city, address.postal_code, address.country (default 'BG')
- Table: code, name, is_active, is_default. TrashedFilter
- StockLocationsRelationManager: name, code, is_active. CRUD in modal

**12b. StockItemResource** — `app/Filament/Resources/StockItems/` (read-only)

Structure: `StockItemResource.php`, `Tables/StockItemsTable.php`, `Pages/ListStockItems.php`

- `NavigationGroup::Warehouse`, icon: `OutlinedArchiveBox`, label: 'Stock Levels'
- `canCreate()` returns false
- Table: productVariant.product.name, productVariant.sku, productVariant.name (variant), warehouse.name, stockLocation.name (placeholder '-'), quantity (4dp), reserved_quantity (toggleable hidden), available_quantity (computed state column)
- Filters: warehouse, product (via productVariant join)

**12c. StockMovementResource** — `app/Filament/Resources/StockMovements/` (read-only)

Structure: `StockMovementResource.php`, `Tables/StockMovementsTable.php`, `Pages/ListStockMovements.php`

- `NavigationGroup::Warehouse`, icon: `OutlinedArrowsRightLeft`, label: 'Stock Movements'
- `canCreate()` returns false
- Table: moved_at (dateTime), type (badge), productVariant.product.name, productVariant.sku, warehouse.name, quantity (4dp, color green/red), notes (toggleable)
- Filters: type (SelectFilter), warehouse (SelectFilter), moved_at (date range)
- Default sort: moved_at desc

**12d. StockAdjustmentPage** — `app/Filament/Pages/StockAdjustmentPage.php`

Custom standalone Filament Page (not a resource). Implements `HasForms`.

- `NavigationGroup::Warehouse`, icon: `OutlinedAdjustmentsHorizontal`, label: 'Stock Adjustment'
- `canAccess()`: requires `create_stock_movement` permission
- Form: product_id (Select, live) → product_variant_id (Select, reactive, visible when product selected) → warehouse_id (Select, live) → stock_location_id (Select, reactive, nullable) → quantity (TextInput numeric, signed) → reason (Textarea, required)
- Submit action `adjust()`: calls `StockService::adjust()`, sends success notification, resets form
- Blade view: `resources/views/filament/pages/stock-adjustment-page.blade.php` with `<x-filament-panels::page>` wrapper

---

### Step 13 — Navigation Group Migration

**MODIFY** all existing resources from plain strings to `NavigationGroup` enum:

| File | Old | New |
|------|-----|-----|
| `Resources/Partners/PartnerResource.php` | `'CRM'` | `NavigationGroup::Crm` |
| `Resources/Contracts/ContractResource.php` | `'CRM'` | `NavigationGroup::Crm` |
| `Resources/Tags/TagResource.php` | `'CRM'` | `NavigationGroup::Crm` |
| `Resources/Currencies/CurrencyResource.php` | `'Settings'` | `NavigationGroup::Settings` |
| `Resources/VatRates/VatRateResource.php` | `'Settings'` | `NavigationGroup::Settings` |
| `Resources/DocumentSeries/DocumentSeriesResource.php` | `'Settings'` | `NavigationGroup::Settings` |
| `Resources/Roles/RoleResource.php` | `'Settings'` | `NavigationGroup::Settings` |
| `Resources/TenantUsers/TenantUserResource.php` | `'Settings'` | `NavigationGroup::Settings` |
| `Pages/CompanySettingsPage.php` | `'Settings'` | `NavigationGroup::Settings` |
| `Pages/SubscriptionPage.php` | `'Settings'` | `NavigationGroup::Settings` |

Each file: add `use App\Enums\NavigationGroup;`, update `$navigationGroup` value.

---

### Step 14 — Tests

All in `tests/Feature/`. Pest syntax. Use `$tenant->run()` for tenant context. Follow existing patterns from `ExchangeRatePolicyTest.php` and `TenantOnboardingServiceTest.php`.

**14a. `CategoryTest.php`**
- Category CRUD, slug auto-generation, slug uniqueness
- Depth: root=0, child=1, grandchild=2, depth 3 throws InvalidArgumentException
- Scopes: roots(), active()
- Parent/children relationships
- SoftDelete + restore

**14b. `ProductCatalogTest.php`**
- Product CRUD, type enum
- Auto-creates default ProductVariant on created (is_default=true, same name/code)
- is_stockable defaults: Stock→true, Service→false, Bundle→true
- hasVariants() false with only default, true with non-default active variant
- ProductVariant effectivePurchasePrice/effectiveSalePrice fallback to product
- Belongs to category, unit, vatRate

**14c. `StockServiceTest.php`**
- receive: creates StockItem + Movement(Purchase, positive qty)
- receive: increments existing StockItem
- issue: decrements StockItem, Movement(Sale, negative qty)
- issue: throws InsufficientStockException with structured data
- adjust: positive qty increments, negative decrements, Movement(Adjustment)
- transfer: paired TransferOut + TransferIn movements, source decremented, destination incremented
- transfer: throws InsufficientStockException when source insufficient
- receive with custom type (e.g., Return)

**14d. `StockMovementTest.php`**
- Cannot update (throws RuntimeException)
- Cannot delete (throws RuntimeException)
- Records correct type, supports reference morphs

**14e. `WarehouseTest.php`**
- Warehouse CRUD, code uniqueness
- Single-default enforcement (setting one unsets others)
- StockLocation belongs to warehouse
- SoftDelete + restore

**14f. `CatalogPolicyTest.php`**
- CategoryPolicy, UnitPolicy, ProductPolicy, ProductVariantPolicy
- viewAny false without tenant context
- All 7 methods for admin in tenant context
- Follow ExchangeRatePolicyTest pattern exactly

**14g. `WarehousePolicyTest.php`**
- WarehousePolicy, StockLocationPolicy: all 7 methods for admin
- StockItemPolicy: delete/forceDelete always false
- StockMovementPolicy: update/delete always false

**14h. `TenantOnboardingServicePhase2Test.php`**
- Onboard seeds units (UnitSeeder)
- Onboard creates default Main Warehouse (is_default=true, code='MAIN')
- Idempotent: calling twice doesn't create duplicates
- Sets default supported locales

---

### Step 15 — Pint + Final Verification

```bash
vendor/bin/pint --dirty --format agent
./vendor/bin/sail artisan test --compact
```

All existing 232 tests must still pass. New tests should bring total to ~280+.

---

## File Summary

| Category | New | Modified | Deleted |
|----------|-----|----------|---------|
| Enums | 2 | 1 | 1 |
| Migrations | 8 | 0 | 0 |
| Models | 8 | 0 | 0 |
| Exceptions | 1 | 0 | 0 |
| Services | 1 | 1 | 0 |
| Factories | 8 | 0 | 0 |
| Seeders | 1 | 1 | 0 |
| Policies | 8 | 0 | 0 |
| Filament Resources | ~36 | 10 | 0 |
| Blade Views | 1 | 0 | 0 |
| Tests | 8 | 0 | 0 |
| Providers | 0 | 2 | 0 |
| **Total** | **~82** | **15** | **1** |

## Critical Files

These 5 files anchor the phase and must be implemented with particular care:

1. **`app/Models/Product.php`** — Boot events for auto-creating default variant and is_stockable derivation. The always-variant pattern that all downstream code depends on.
2. **`app/Services/StockService.php`** — Core business logic. Transaction boundaries, bcmath arithmetic, InsufficientStockException flow.
3. **`database/migrations/tenant/..._create_stock_items_table.php`** — PostgreSQL partial unique index for COALESCE(stock_location_id, 0) is the trickiest migration detail.
4. **`database/seeders/RolesAndPermissionsSeeder.php`** — Must add 8 models and update 3 role permission sets without breaking existing assignments.
5. **`app/Models/Category.php`** — Depth enforcement in saving event with parent-chain walking and descendant-depth validation.

## Verification Plan

1. Run `php artisan tenants:migrate` to verify all migrations apply cleanly
2. Run full test suite: `./vendor/bin/sail artisan test --compact` — all 232 existing + ~50 new tests must pass
3. Start dev server, create a test tenant, verify:
   - Catalog nav group shows Category, Unit, Product resources
   - Warehouse nav group shows Warehouse, Stock Levels, Stock Movements, Stock Adjustment
   - Create a category tree (3 levels), verify 4th level blocked
   - Create a product, verify default variant auto-created
   - Add explicit variants, verify they appear in relation manager
   - Create a warehouse, receive stock via adjustment page
   - Verify stock levels and movement audit trail
   - Switch locale via LocaleSwitcher, verify translatable fields change
4. Run `vendor/bin/pint --dirty --format agent` for code style
