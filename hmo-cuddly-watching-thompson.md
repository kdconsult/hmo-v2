# HMO ERP-Light SaaS - Implementation Plan

## Context

Build a full SaaS SMB ERP-light system targeting EU markets (Bulgaria first) with Filament v5. The system covers CRM, WMS, Sales/Invoicing with Bulgarian SUPTO fiscal compliance, Field Service, Payments/Reconciliation, and KPIs. This is a greenfield project on a fresh Laravel 13 + Filament v5 scaffold.

---

## Architecture Decisions

| Decision | Choice |
|----------|--------|
| Target market | EU-wide, Bulgaria first (SUPTO/NRA, BNB/ECB, 5-day rule) |
| Multi-tenancy | Database-per-tenant, `stancl/tenancy`, subdomain-based |
| Database | PostgreSQL |
| Panels | Single `/admin` panel (role-based nav) + separate `/landlord` panel |
| RBAC | `spatie/laravel-permission` |
| Audit trail | `spatie/laravel-activitylog` |
| Users | Multi-tenant (one user, multiple companies, tenant selector) |
| i18n | Full from start (Laravel's translation system) |
| PDF generation | Blade + `barryvdh/laravel-dompdf` |
| Fiscal printers | ErpNet.FP REST API (localhost:8001) |
| Money storage | `decimal(15,2)` for totals, `decimal(19,4)` for item prices |
| Partner model | Unified (is_customer + is_supplier flags) |
| Inventory | Movement-based + cached StockLevel |
| Bundles | Single-level only |
| Serial/batch | Optional per nomenclature item |
| Doc conversion | Copy + link reference (Quote -> Order -> Invoice) |
| VAT pricing | Both inclusive/exclusive, multi-rate per document |
| VAT validation | `danielebarbaro/laravel-vat-eu-validator` for VIES + custom `VatCalculationService` |
| VAT rate seeding | `ibericode/vat-rates` JSON data repo for initial EU rate seeding |
| Doc numbering | Fully configurable (prefix, year, reset, padding, separator) |
| Transfers | Partial receives supported |
| Credit notes | Partial corrections, both sales & purchases |
| Time tracking | Manual + timer option |
| Pending consumption | Deducts stock immediately |
| Cash registers | Multi-register + shifts + Z-reports |
| Installments | Predefined schedule + ad-hoc payments |
| Inventory counts | Full + cycle counting |
| Internal use | Formal Internal Consumption Note |
| Bank import | CSV/CAMT.053 upload + API + manual entry |
| FX rates | Auto sync BNB/ECB + manual override |
| Notifications | In-app (Filament DB) + email |
| E-commerce | Deferred |
| OCR | Deferred |

---

## Package Dependencies (to install)

```
# Production
composer require stancl/tenancy
composer require spatie/laravel-permission
composer require spatie/laravel-activitylog
composer require barryvdh/laravel-dompdf
composer require danielebarbaro/laravel-vat-eu-validator  # VIES VAT number validation via REST API

# Dev (already installed)
# filament/filament, pestphp/pest, laravel/pint
```

### VAT/Tax Engine Strategy

Our `vat_rates` table is the **source of truth** (database-driven, per-tenant configurable). Packages complement it:

| Concern | Solution |
|---------|----------|
| **VAT rate storage** | Our `VatRate` model in tenant DB — fully configurable per company |
| **Rate seeding/sync** | Artisan command fetching from [ibericode/vat-rates](https://github.com/ibericode/vat-rates) JSON repo to seed initial EU rates into `vat_rates` table |
| **VIES VAT number validation** | `danielebarbaro/laravel-vat-eu-validator` (REST-based, no SOAP) — validates partner VAT numbers for B2B reverse charge |
| **VAT calculation** | Custom `App\Services\VatCalculationService` — handles inclusive/exclusive pricing, multi-rate per document, reverse charge (zero VAT + legal text when VIES-validated B2B), OSS destination-country rates for B2C cross-border |
| **Reverse charge logic** | If partner has valid EU VAT number (VIES check) AND is in a different EU country → zero VAT, add "Reverse charge" legal text to document footer |
| **OSS (One Stop Shop)** | For B2C cross-border EU sales: apply destination country's VAT rate from `vat_rates` table (rates kept current via sync command) |

**Key files to create:**
- `app/Services/VatCalculationService.php` — Core VAT engine
- `app/Services/ViesValidationService.php` — Wraps `laravel-vat-eu-validator` with caching
- `app/Console/Commands/SyncEuVatRatesCommand.php` — Fetches ibericode/vat-rates JSON, upserts into `vat_rates`
- `database/seeders/VatRateSeeder.php` — Initial seed using the sync command data

---

## Build Phases

### Phase 1: Core Settings + Multi-tenancy + Auth/Roles + CRM
### Phase 2: Warehouse/WMS + Nomenclature/Catalog
### Phase 3: Sales/Invoicing + Purchases + SUPTO/Fiscal
### Phase 4: Field Service + Payments/Reconciliation
### Phase 5: KPIs/Intelligence + Reports

---

## Central Database Schema (Landlord)

stancl/tenancy manages the central DB. Filament's built-in `->tenant()` is NOT used (that's for shared-DB tenancy). Instead, stancl resolves tenant from subdomain and switches the DB connection.

### Model: Tenant
- **Table:** `tenants`
- **Attributes:** id (string/UUID, primary), name (string, required), slug (string, unique, subdomain), email (string), phone (string, nullable), address_line_1 (string, nullable), city (string, nullable), postal_code (string, nullable), country_code (string(2), default:'BG'), vat_number (string, nullable), eik (string, nullable, Bulgarian EIK/BULSTAT), mol (string, nullable), logo_path (string, nullable), locale (string, default:'bg'), timezone (string, default:'Europe/Sofia'), default_currency_code (string(3), default:'BGN'), subscription_plan (string, nullable), subscription_ends_at (timestamp, nullable), data (json, nullable), timestamps
- **Relationships:** hasMany Domain, belongsToMany User via tenant_user
- **Traits:** HasFactory

### Model: Domain
- **Table:** `domains`
- **Attributes:** id (bigint, primary), tenant_id (string, FK tenants.id), domain (string, unique), timestamps
- **Relationships:** belongsTo Tenant
- **Traits:** HasFactory

### Model: User (modify existing)
- **File:** `app/Models/User.php`
- **Add attributes:** avatar_path (string, nullable), locale (string, nullable), is_landlord (boolean, default:false), last_login_at (timestamp, nullable)
- **Add relationships:** belongsToMany Tenant via tenant_user
- **Implements:** `Filament\Models\Contracts\HasTenants`, `Filament\Models\Contracts\FilamentUser`
- **Methods:** getTenants(), canAccessTenant(), canAccessPanel()

### Pivot: tenant_user
- **Attributes:** id, tenant_id, user_id, timestamps
- **Constraints:** unique(tenant_id, user_id)

---

## Tenant Database Schema (per company)

All models below live in each tenant's database. No `company_id` column needed (full DB isolation).

---

## Enums (all in `app/Enums/`)

All implement `Filament\Support\Contracts\HasLabel`. Badge-displayed ones also implement `HasColor` and `HasIcon`.

| Enum | Cases | Extra Interfaces |
|------|-------|-----------------|
| PartnerType | Individual, Company | HasColor |
| DocumentStatus | Draft, Confirmed, Sent, PartiallyPaid, Paid, Overdue, Cancelled | HasColor, HasIcon |
| QuoteStatus | Draft, Sent, Accepted, Rejected, Expired, Converted | HasColor, HasIcon |
| OrderStatus | Draft, Confirmed, InProgress, PartiallyFulfilled, Fulfilled, Cancelled | HasColor, HasIcon |
| PurchaseOrderStatus | Draft, Sent, Confirmed, PartiallyReceived, Received, Cancelled | HasColor, HasIcon |
| PaymentMethod | Cash, BankTransfer, Card, DirectDebit | HasIcon |
| PaymentDirection | Incoming, Outgoing | - |
| MovementType | Purchase, Sale, TransferOut, TransferIn, Adjustment, Return, InternalConsumption, Production, InitialStock | HasColor |
| TransferStatus | Draft, InTransit, PartiallyReceived, Received, Cancelled | HasColor, HasIcon |
| InventoryCountStatus | Draft, InProgress, Completed, Approved | HasColor |
| CountType | Full, Cycle | - |
| TrackingType | None, Serial, Batch | - |
| PricingMode | VatExclusive, VatInclusive | - |
| NomenclatureType | Stock, Service, Virtual, Bundle | HasColor |
| CreditNoteReason | Return, Discount, Error, Damaged, Other | - |
| DebitNoteReason | PriceIncrease, AdditionalCharge, Error, Other | - |
| FiscalReceiptStatus | Pending, Printed, Failed, Annulled | HasColor |
| CashRegisterShiftStatus | Open, Closed | HasColor |
| JobSheetStatus | Draft, Scheduled, InProgress, OnHold, Completed, Invoiced | HasColor, HasIcon |
| TimeEntryType | Manual, Timer | - |
| ReconciliationStatus | Unmatched, Matched, PartiallyMatched, Ignored | HasColor |
| BankTransactionType | Credit, Debit | - |
| BankImportSource | Csv, Camt053, Api, Manual | - |
| InstallmentStatus | Pending, PartiallyPaid, Paid, Overdue | HasColor |
| DocumentType | Quote, SalesOrder, Invoice, CreditNote, DebitNote, ProformaInvoice, DeliveryNote, PurchaseOrder, SupplierInvoice, SupplierCreditNote, GoodsReceivedNote, InternalConsumptionNote | - |
| ContractStatus | Draft, Active, Suspended, Expired, Cancelled | HasColor, HasIcon |
| KpiPeriod | Daily, Weekly, Monthly, Quarterly, Yearly | - |

---

## PHASE 1: Core Settings + Multi-tenancy + Auth/Roles + CRM

### Models

#### TenantUser
- **Table:** `tenant_users`
- **Purpose:** Local user profile within a tenant, links to central User
- **Attributes:** id, user_id (bigint, NOT a DB FK - cross-DB), display_name (nullable), job_title (nullable), phone (nullable), is_active (boolean, default:true), settings (json, nullable), timestamps, deleted_at
- **Relationships:** belongsToMany Role (spatie/permission)
- **Traits:** SoftDeletes, HasFactory, HasRoles

#### CompanySettings
- **Table:** `company_settings`
- **Attributes:** id, group (string), key (string), value (text, nullable), timestamps
- **Constraints:** unique(group, key)
- **Groups:** general, invoicing, fiscal, warehouse, notifications

#### Currency
- **Table:** `currencies`
- **Attributes:** id, code (string(3), unique, ISO 4217), name, symbol, decimal_places (int, default:2), is_active (boolean), timestamps
- **Relationships:** hasMany ExchangeRate

#### ExchangeRate
- **Table:** `exchange_rates`
- **Attributes:** id, currency_id (FK), base_currency_code (string(3), default:'BGN'), rate (decimal(16,6)), source (string: BNB/ECB/manual), date (date), timestamps
- **Constraints:** unique(currency_id, base_currency_code, date)
- **Relationships:** belongsTo Currency

#### VatRate
- **Table:** `vat_rates`
- **Attributes:** id, country_code (string(2), required, default:'BG'), name, rate (decimal(5,2)), type (string: standard/reduced/super_reduced/zero/exempt), is_default (boolean), is_active (boolean), sort_order (int), effective_from (date, nullable), effective_to (date, nullable), timestamps, deleted_at
- **Traits:** SoftDeletes
- **Note:** Country-aware for EU-wide support. `SyncEuVatRatesCommand` populates rates for all EU countries from ibericode/vat-rates. VIES validation uses `danielebarbaro/laravel-vat-eu-validator`.

#### DocumentSeries
- **Table:** `document_series`
- **Attributes:** id, document_type (DocumentType enum), name, prefix (nullable), separator (default:'-'), include_year (boolean, default:true), year_format (string, default:'Y'), padding (int, default:5), next_number (int, default:1), reset_yearly (boolean, default:true), is_default (boolean), is_active (boolean), timestamps, deleted_at
- **Traits:** SoftDeletes
- **Note:** Generates "INV-2026-00001". next_number incremented atomically with DB locks.

#### Partner
- **Table:** `partners`
- **Attributes:** id, type (PartnerType enum), name, company_name (nullable), eik (nullable), vat_number (nullable), mol (nullable), email (nullable), phone (nullable), secondary_phone (nullable), website (nullable), is_customer (boolean), is_supplier (boolean), default_currency_code (nullable), default_payment_term_days (int, nullable), default_payment_method (PaymentMethod, nullable), default_vat_rate_id (FK nullable), credit_limit (decimal(15,2), nullable), discount_percent (decimal(5,2), nullable), notes (text, nullable), is_active (boolean), timestamps, deleted_at
- **Relationships:** belongsTo VatRate (default), hasMany PartnerAddress, hasMany PartnerContact, hasMany PartnerBankAccount, hasMany Quote, hasMany SalesOrder, hasMany Invoice, hasMany PurchaseOrder, hasMany SupplierInvoice, hasMany Payment, hasMany Contract, morphToMany Tag
- **Traits:** SoftDeletes, HasFactory, LogsActivity

#### PartnerAddress
- **Table:** `partner_addresses`
- **Attributes:** id, partner_id (FK), label (nullable), address_line_1, address_line_2 (nullable), city, region (nullable), postal_code (nullable), country_code (string(2), default:'BG'), is_billing (boolean), is_shipping (boolean), is_default (boolean), timestamps
- **Relationships:** belongsTo Partner

#### PartnerContact
- **Table:** `partner_contacts`
- **Attributes:** id, partner_id (FK), name, position (nullable), email (nullable), phone (nullable), is_primary (boolean), notes (nullable), timestamps
- **Relationships:** belongsTo Partner

#### PartnerBankAccount
- **Table:** `partner_bank_accounts`
- **Attributes:** id, partner_id (FK), bank_name, iban, bic (nullable), currency_code (default:'BGN'), is_default (boolean), timestamps
- **Relationships:** belongsTo Partner

#### Contract (SLA/Maintenance)
- **Table:** `contracts`
- **Attributes:** id, contract_number (string, unique), document_series_id (FK, nullable), partner_id (FK), status (ContractStatus enum), type (string: maintenance, sla, subscription), start_date (date), end_date (date, nullable), auto_renew (boolean, default:false), monthly_fee (decimal(15,2), nullable), currency_code (string(3), default:'BGN'), included_hours (decimal(8,2), nullable), included_materials_budget (decimal(15,2), nullable), used_hours (decimal(8,2), default:0), used_materials (decimal(15,2), default:0), billing_day (int, nullable), notes (text, nullable), created_by (nullable), timestamps, deleted_at
- **Relationships:** belongsTo Partner, belongsTo DocumentSeries (nullable), hasMany JobSheet, hasMany Invoice (via source_type/source_id)
- **Traits:** SoftDeletes, HasFactory, LogsActivity

#### Tag
- **Table:** `tags`
- **Attributes:** id, name (unique), color (nullable), timestamps
- **Relationships:** morphedByMany Partner, morphedByMany NomenclatureItem

### Phase 1 Filament Resources

**Landlord Panel (`/landlord`):**
- TenantResource: CRUD tenants, manage domains (RelationManager), view subscription status
- CentralUserResource: CRUD central users, manage tenant memberships

**Admin Panel (`/admin`) - Settings group:**
- CompanySettingsPage (custom page, not resource - uses spatie/laravel-settings or key-value)
- CurrencyResource: CRUD currencies, inline ExchangeRate management
- VatRateResource: CRUD VAT rates
- DocumentSeriesResource: CRUD series with preview of generated number format
- TenantUserResource: Manage team members, assign roles
- RoleResource: Manage spatie roles/permissions

**Admin Panel - CRM group:**
- PartnerResource: Full CRUD with tabs (Addresses, Contacts, Bank Accounts as RelationManagers). Filter by is_customer/is_supplier. View financial ledger summary.
- ContractResource: CRUD with partner selection, SLA terms, usage tracking
- TagResource: Simple CRUD

### Phase 1 Artisan Commands
```bash
composer require stancl/tenancy
php artisan tenancy:install
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
composer require spatie/laravel-activitylog
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
composer require barryvdh/laravel-dompdf
composer require danielebarbaro/laravel-vat-eu-validator
php artisan vendor:publish --provider="DanieleBarbaro\LaravelVatEuValidator\VatValidatorServiceProvider"

# Create Landlord panel provider
php artisan make:filament-panel landlord --no-interaction

# Create enums
php artisan make:class App/Enums/PartnerType --no-interaction
php artisan make:class App/Enums/DocumentType --no-interaction
php artisan make:class App/Enums/PaymentMethod --no-interaction
php artisan make:class App/Enums/PricingMode --no-interaction
php artisan make:class App/Enums/ContractStatus --no-interaction

# Create models with migrations, factories, seeders
php artisan make:model Tenant -mfs --no-interaction
php artisan make:model Domain -mf --no-interaction
php artisan make:model TenantUser -mf --no-interaction
php artisan make:model CompanySettings -m --no-interaction
php artisan make:model Currency -mfs --no-interaction
php artisan make:model ExchangeRate -mf --no-interaction
php artisan make:model VatRate -mfs --no-interaction
php artisan make:model DocumentSeries -mfs --no-interaction
php artisan make:model Partner -mfs --no-interaction
php artisan make:model PartnerAddress -mf --no-interaction
php artisan make:model PartnerContact -mf --no-interaction
php artisan make:model PartnerBankAccount -mf --no-interaction
php artisan make:model Contract -mf --no-interaction
php artisan make:model Tag -mf --no-interaction

# Create Filament resources
php artisan make:filament-resource Tenant --generate --view --no-interaction
php artisan make:filament-resource Partner --generate --view --soft-deletes --no-interaction
php artisan make:filament-resource Contract --generate --view --soft-deletes --no-interaction
php artisan make:filament-resource Currency --generate --no-interaction
php artisan make:filament-resource VatRate --generate --soft-deletes --no-interaction
php artisan make:filament-resource DocumentSeries --generate --soft-deletes --no-interaction
php artisan make:filament-resource TenantUser --generate --soft-deletes --no-interaction
php artisan make:filament-resource Tag --generate --no-interaction

# Create RelationManagers for Partner
php artisan make:filament-relation-manager PartnerResource addresses address_line_1 --generate --no-interaction
php artisan make:filament-relation-manager PartnerResource contacts name --generate --no-interaction
php artisan make:filament-relation-manager PartnerResource bankAccounts iban --generate --no-interaction

# Create VAT/Tax services and commands
php artisan make:class App/Services/VatCalculationService --no-interaction
php artisan make:class App/Services/ViesValidationService --no-interaction
php artisan make:command SyncEuVatRatesCommand --no-interaction

# Create seeders for roles/permissions
php artisan make:seeder RolesAndPermissionsSeeder --no-interaction
php artisan make:seeder CurrencySeeder --no-interaction
php artisan make:seeder VatRateSeeder --no-interaction
```

---

## PHASE 2: Warehouse/WMS + Nomenclature

### Models

#### NomenclatureCategory
- **Table:** `nomenclature_categories`
- **Attributes:** id, parent_id (self-FK, nullable), name, slug, description (text, nullable), sort_order (int, default:0), is_active (boolean), timestamps, deleted_at
- **Relationships:** belongsTo self (parent), hasMany self (children), hasMany NomenclatureItem
- **Traits:** SoftDeletes

#### UnitOfMeasure
- **Table:** `units_of_measure`
- **Attributes:** id, name, abbreviation, is_active (boolean), timestamps

#### NomenclatureItem
- **Table:** `nomenclature_items`
- **Attributes:** id, nomenclature_category_id (FK, nullable), unit_of_measure_id (FK), default_vat_rate_id (FK, nullable), sku (nullable, unique), barcode (nullable, unique), name, description (text, nullable), type (NomenclatureType enum), buy_price (decimal(19,4), nullable), sell_price (decimal(19,4), nullable), min_sell_price (decimal(19,4), nullable), tracking_type (TrackingType enum, default:None), is_purchasable (boolean, default:true), is_sellable (boolean, default:true), min_stock_level (decimal(15,4), nullable), reorder_point (decimal(15,4), nullable), reorder_quantity (decimal(15,4), nullable), weight_kg (decimal(10,4), nullable), dimensions_json (json, nullable), image_path (nullable), is_active (boolean), timestamps, deleted_at
- **Relationships:** belongsTo Category, belongsTo UnitOfMeasure, belongsTo VatRate (default), hasMany StockLevel, hasMany StockMovement, hasMany BundleItem (as bundle), hasMany BundleItem (as component), hasMany PriceListItem, morphToMany Tag
- **Traits:** SoftDeletes, LogsActivity

#### BundleItem
- **Table:** `bundle_items`
- **Attributes:** id, bundle_id (FK nomenclature_items.id), nomenclature_item_id (FK), quantity (decimal(15,4)), sort_order (int), timestamps
- **Constraints:** unique(bundle_id, nomenclature_item_id). Single-level only enforced in app logic.

#### PriceList
- **Table:** `price_lists`
- **Attributes:** id, name, currency_code (default:'BGN'), pricing_mode (PricingMode enum), valid_from (date, nullable), valid_to (date, nullable), is_default (boolean), is_active (boolean), timestamps, deleted_at
- **Relationships:** hasMany PriceListItem
- **Traits:** SoftDeletes

#### PriceListItem
- **Table:** `price_list_items`
- **Attributes:** id, price_list_id (FK), nomenclature_item_id (FK), price (decimal(19,4)), min_quantity (decimal(15,4), nullable), timestamps
- **Constraints:** unique(price_list_id, nomenclature_item_id, min_quantity)

#### Warehouse
- **Table:** `warehouses`
- **Attributes:** id, name, code (unique), type (string: warehouse, van, virtual), address_line_1 (nullable), city (nullable), is_default (boolean), is_active (boolean), timestamps, deleted_at
- **Relationships:** hasMany WarehouseLocation, hasMany StockLevel, hasMany StockMovement, hasMany StockTransfer (source/destination)
- **Traits:** SoftDeletes

#### WarehouseLocation
- **Table:** `warehouse_locations`
- **Attributes:** id, warehouse_id (FK), name, barcode (nullable, unique), is_active (boolean), timestamps

#### StockLevel (cached)
- **Table:** `stock_levels`
- **Attributes:** id, warehouse_id (FK), nomenclature_item_id (FK), quantity_on_hand (decimal(15,4), default:0), quantity_reserved (decimal(15,4), default:0), quantity_available (decimal(15,4), default:0, computed: on_hand - reserved), quantity_incoming (decimal(15,4), default:0), cost_average (decimal(19,4), nullable), last_counted_at (timestamp, nullable), updated_at
- **Constraints:** unique(warehouse_id, nomenclature_item_id)
- **Note:** Updated via observers on StockMovement creation.

#### StockMovement
- **Table:** `stock_movements`
- **Attributes:** id, warehouse_id (FK), nomenclature_item_id (FK), movement_type (MovementType enum), quantity (decimal(15,4), positive=in, negative=out), unit_cost (decimal(19,4), nullable), total_cost (decimal(15,2), nullable), reference_type (string, nullable, polymorphic), reference_id (bigint, nullable), serial_number (nullable), batch_number (nullable), batch_expiry_date (date, nullable), notes (text, nullable), created_by (nullable), timestamps
- **Relationships:** belongsTo Warehouse, belongsTo NomenclatureItem, morphTo reference, belongsTo TenantUser (created_by)
- **Traits:** LogsActivity
- **Note:** IMMUTABLE once created. Corrections = new opposing movement.

#### StockTransfer
- **Table:** `stock_transfers`
- **Attributes:** id, transfer_number (unique), document_series_id (FK, nullable), source_warehouse_id (FK), destination_warehouse_id (FK), status (TransferStatus enum, default:Draft), notes (text, nullable), shipped_at (nullable), received_at (nullable), created_by (nullable), timestamps, deleted_at
- **Relationships:** belongsTo source/destination Warehouse, hasMany StockTransferItem, morphMany StockMovement
- **Traits:** SoftDeletes, LogsActivity

#### StockTransferItem
- **Table:** `stock_transfer_items`
- **Attributes:** id, stock_transfer_id (FK), nomenclature_item_id (FK), quantity_sent (decimal(15,4)), quantity_received (decimal(15,4), default:0), serial_number (nullable), batch_number (nullable), notes (nullable), timestamps
- **Note:** Partial receives: quantity_received can be < quantity_sent.

#### InventoryCount
- **Table:** `inventory_counts`
- **Attributes:** id, count_number (unique), warehouse_id (FK), count_type (CountType enum), status (InventoryCountStatus enum, default:Draft), count_date (date), notes (text, nullable), approved_by (nullable), approved_at (nullable), created_by (nullable), timestamps
- **Relationships:** belongsTo Warehouse, hasMany InventoryCountItem, morphMany StockMovement
- **Traits:** LogsActivity

#### InventoryCountItem
- **Table:** `inventory_count_items`
- **Attributes:** id, inventory_count_id (FK), nomenclature_item_id (FK), system_quantity (decimal(15,4)), counted_quantity (decimal(15,4), nullable), difference (decimal(15,4), nullable, computed), serial_number (nullable), batch_number (nullable), notes (nullable), timestamps

#### InternalConsumptionNote
- **Table:** `internal_consumption_notes`
- **Attributes:** id, document_number (unique), document_series_id (FK, nullable), warehouse_id (FK), reason, notes (text, nullable), consumed_at (date), created_by (nullable), timestamps, deleted_at
- **Relationships:** belongsTo Warehouse, hasMany InternalConsumptionNoteItem, morphMany StockMovement
- **Traits:** SoftDeletes, LogsActivity

#### InternalConsumptionNoteItem
- **Table:** `internal_consumption_note_items`
- **Attributes:** id, internal_consumption_note_id (FK), nomenclature_item_id (FK), quantity (decimal(15,4)), unit_cost (decimal(19,4), nullable), total_cost (decimal(15,2), nullable), notes (nullable), timestamps

### Phase 2 Filament Resources

**Catalog group:**
- NomenclatureCategoryResource (tree/nested structure)
- NomenclatureItemResource (tabs for General, Pricing, Stock, Bundle Components as Repeater)
- UnitOfMeasureResource
- PriceListResource (with PriceListItem RelationManager)

**Warehouse group:**
- WarehouseResource (with WarehouseLocation RelationManager)
- StockLevelResource (read-only, filterable by warehouse/category)
- StockTransferResource (with StockTransferItem, status workflow actions)
- InventoryCountResource (with CountItem, approve action)
- InternalConsumptionNoteResource

---

## PHASE 3: Sales/Invoicing + Purchases + SUPTO/Fiscal

### Sales Models

#### Quote
- **Table:** `quotes`
- **Attributes:** id, quote_number (unique), document_series_id (FK, nullable), partner_id (FK), partner_address_id (FK, nullable), status (QuoteStatus, default:Draft), currency_code (default:'BGN'), exchange_rate (decimal(16,6), default:1), pricing_mode (PricingMode), subtotal (decimal(15,2)), discount_amount (decimal(15,2)), tax_amount (decimal(15,2)), total (decimal(15,2)), valid_until (date, nullable), notes (text, nullable), internal_notes (text, nullable), terms_and_conditions (text, nullable), issued_at (date, nullable), created_by (nullable), timestamps, deleted_at
- **Relationships:** belongsTo Partner, hasMany QuoteItem, hasMany SalesOrder (via source_type/source_id link)
- **Traits:** SoftDeletes, LogsActivity

#### QuoteItem
- **Table:** `quote_items`
- **Attributes:** id, quote_id (FK), nomenclature_item_id (FK, nullable for free-text), description, quantity (decimal(15,4)), unit_price (decimal(19,4)), discount_percent (decimal(5,2), default:0), discount_amount (decimal(15,2), default:0), vat_rate_id (FK), vat_amount (decimal(15,2)), line_total (decimal(15,2)), line_total_with_vat (decimal(15,2)), sort_order (int), timestamps

#### SalesOrder
- **Table:** `sales_orders`
- **Attributes:** id, order_number (unique), document_series_id (FK, nullable), partner_id (FK), partner_address_id (FK, nullable), shipping_address_id (FK, nullable), warehouse_id (FK, nullable), source_type (nullable), source_id (nullable), status (OrderStatus, default:Draft), currency_code, exchange_rate, pricing_mode, subtotal, discount_amount, tax_amount, total, expected_delivery_date (date, nullable), notes, internal_notes, ordered_at (date, nullable), created_by, timestamps, deleted_at
- **Relationships:** belongsTo Partner, hasMany SalesOrderItem, hasMany Invoice (via source link), morphMany StockMovement
- **Traits:** SoftDeletes, LogsActivity

#### SalesOrderItem
- **Table:** `sales_order_items`
- **Attributes:** id, sales_order_id (FK), nomenclature_item_id (FK, nullable), quote_item_id (FK, nullable, traceability link), description, quantity, quantity_fulfilled (default:0), unit_price, discount_percent, discount_amount, vat_rate_id (FK), vat_amount, line_total, line_total_with_vat, sort_order, timestamps

#### Invoice
- **Table:** `invoices`
- **Attributes:** id, invoice_number (unique), document_series_id (FK, nullable), partner_id (FK), partner_address_id (FK, nullable), warehouse_id (FK, nullable), source_type (nullable), source_id (nullable), status (DocumentStatus, default:Draft), currency_code, exchange_rate, pricing_mode, subtotal, discount_amount, tax_amount, total, amount_paid (decimal(15,2), default:0), amount_due (decimal(15,2), default:0), issued_at (date), due_date (date), tax_point_date (date, nullable, Bulgarian law), payment_method (nullable), notes, internal_notes, terms_and_conditions, is_proforma (boolean, default:false), is_advance_payment (boolean, default:false), created_by, timestamps, deleted_at
- **Relationships:** belongsTo Partner, hasMany InvoiceItem, hasMany CreditNote, hasMany DebitNote, hasMany PaymentAllocation (morphMany), hasMany FiscalReceipt, hasMany InstallmentSchedule, morphMany StockMovement
- **Traits:** SoftDeletes, LogsActivity
- **Note:** 5-day rule enforced by comparing tax_point_date to issued_at.

#### InvoiceItem
- **Table:** `invoice_items`
- **Attributes:** id, invoice_id (FK), nomenclature_item_id (FK, nullable), sales_order_item_id (FK, nullable, traceability), description, quantity, unit_price (decimal(19,4)), discount_percent, discount_amount, vat_rate_id (FK), vat_amount, line_total, line_total_with_vat, sort_order, timestamps
- **Relationships:** hasMany CreditNoteItem (via invoice_item_id), hasMany DebitNoteItem

#### CreditNote
- **Table:** `credit_notes`
- **Attributes:** id, credit_note_number (unique), document_series_id (FK, nullable), invoice_id (FK, parent), partner_id (FK), status (DocumentStatus), currency_code, exchange_rate, reason (CreditNoteReason enum), reason_description (text, nullable), subtotal, tax_amount, total, issued_at (date), created_by, timestamps, deleted_at
- **Relationships:** belongsTo Invoice (parent), hasMany CreditNoteItem, hasMany PaymentAllocation (morphMany), hasMany FiscalReceipt
- **Traits:** SoftDeletes, LogsActivity

#### CreditNoteItem
- **Table:** `credit_note_items`
- **Attributes:** id, credit_note_id (FK), invoice_item_id (FK, links to specific parent invoice line), nomenclature_item_id (FK, nullable), description, quantity, unit_price, vat_rate_id (FK), vat_amount, line_total, line_total_with_vat, sort_order, timestamps
- **Note:** SUM(quantity) per invoice_item_id must not exceed original InvoiceItem.quantity.

#### DebitNote (mirrors CreditNote structure)
- **Table:** `debit_notes` / `debit_note_items`
- Same structure as CreditNote/CreditNoteItem with DebitNoteReason enum.

#### DeliveryNote
- **Table:** `delivery_notes`
- **Attributes:** id, delivery_note_number (unique), document_series_id (FK, nullable), sales_order_id (FK, nullable), invoice_id (FK, nullable), partner_id (FK), warehouse_id (FK), shipping_address_id (FK, nullable), delivered_at (date), notes, created_by, timestamps, deleted_at
- **Relationships:** hasMany DeliveryNoteItem
- **Traits:** SoftDeletes, LogsActivity

#### DeliveryNoteItem
- **Table:** `delivery_note_items`
- **Attributes:** id, delivery_note_id (FK), nomenclature_item_id (FK), sales_order_item_id (FK, nullable), quantity, serial_number (nullable), batch_number (nullable), timestamps

### Purchase Models

#### PurchaseOrder
- **Table:** `purchase_orders`
- **Attributes:** id, po_number (unique), document_series_id (FK, nullable), partner_id (FK, supplier), warehouse_id (FK, nullable, destination), status (PurchaseOrderStatus), currency_code, exchange_rate, pricing_mode, subtotal, discount_amount, tax_amount, total, expected_delivery_date (nullable), notes, internal_notes, ordered_at (nullable), created_by, timestamps, deleted_at
- **Relationships:** belongsTo Partner, hasMany PurchaseOrderItem, hasMany GoodsReceivedNote, hasMany SupplierInvoice
- **Traits:** SoftDeletes, LogsActivity

#### PurchaseOrderItem
- **Table:** `purchase_order_items`
- Same structure as SalesOrderItem with quantity_received tracking.

#### GoodsReceivedNote
- **Table:** `goods_received_notes`
- **Attributes:** id, grn_number (unique), document_series_id (FK, nullable), purchase_order_id (FK, nullable), partner_id (FK), warehouse_id (FK), received_at (date), notes, created_by, timestamps, deleted_at
- **Relationships:** hasMany GoodsReceivedNoteItem, morphMany StockMovement
- **Traits:** SoftDeletes, LogsActivity

#### GoodsReceivedNoteItem
- **Table:** `goods_received_note_items`
- **Attributes:** id, goods_received_note_id (FK), purchase_order_item_id (FK, nullable), nomenclature_item_id (FK), quantity, unit_cost (decimal(19,4)), serial_number (nullable), batch_number (nullable), batch_expiry_date (nullable), notes, timestamps

#### SupplierInvoice
- **Table:** `supplier_invoices`
- **Attributes:** id, supplier_invoice_number (string, supplier's own), internal_number (unique), document_series_id (FK, nullable), purchase_order_id (FK, nullable), partner_id (FK), status (DocumentStatus), currency_code, exchange_rate, pricing_mode, subtotal, discount_amount, tax_amount, total, amount_paid, amount_due, issued_at, received_at (nullable), due_date, payment_method (nullable), notes, internal_notes, created_by, timestamps, deleted_at
- **Relationships:** belongsTo Partner, hasMany SupplierInvoiceItem, hasMany SupplierCreditNote, hasMany PaymentAllocation (morphMany)
- **Traits:** SoftDeletes, LogsActivity

#### SupplierInvoiceItem, SupplierCreditNote, SupplierCreditNoteItem
- Mirror the sales CreditNote pattern. SupplierCreditNoteItem links to SupplierInvoiceItem for partial corrections.

### SUPTO/Fiscal Models

#### FiscalReceipt
- **Table:** `fiscal_receipts`
- **Attributes:** id, invoice_id (FK, nullable), credit_note_id (FK, nullable), cash_register_id (FK, nullable), unp (string, unique, NRA Unique Number of Sale), fiscal_receipt_number (nullable, from printer), fiscal_memory_number (nullable), status (FiscalReceiptStatus), receipt_type (string: sale, reversal, storno), total_amount (decimal(15,2)), vat_breakdown (json, array of {rate, base, amount}), payment_method (PaymentMethod), operator_id (nullable), printed_at (nullable), annulled_at (nullable), annulment_reason (text, nullable), error_message (text, nullable), raw_request (json, nullable), raw_response (json, nullable), created_by, timestamps
- **Note:** ErpNet.FP integration. UNP generated before print. Annulment creates reversal receipt (storno).

#### CashRegister
- **Table:** `cash_registers`
- **Attributes:** id, name, code (unique), fiscal_device_serial (nullable), location (nullable), is_active (boolean), timestamps, deleted_at
- **Relationships:** hasMany CashRegisterShift, hasMany FiscalReceipt
- **Traits:** SoftDeletes

#### CashRegisterShift
- **Table:** `cash_register_shifts`
- **Attributes:** id, cash_register_id (FK), opened_by (FK tenant_users), closed_by (FK, nullable), status (CashRegisterShiftStatus), opening_balance (decimal(15,2)), closing_balance (nullable), total_cash_sales, total_card_sales, total_refunds, expected_cash (nullable), actual_cash (nullable), difference (nullable), z_report_number (nullable), z_report_data (json, nullable), opened_at, closed_at (nullable), notes, timestamps
- **Relationships:** belongsTo CashRegister, hasMany Payment
- **Traits:** LogsActivity

### Phase 3 Filament Resources

**Sales group:**
- QuoteResource (with "Convert to Sales Order" action)
- SalesOrderResource (with "Convert to Invoice" action)
- InvoiceResource (with installment schedule, payment recording, fiscal receipt printing actions)
- DeliveryNoteResource
- CreditNoteResource (parent invoice selector, partial line item selection)
- DebitNoteResource

**Purchases group:**
- PurchaseOrderResource
- GoodsReceivedNoteResource (with partial receive support)
- SupplierInvoiceResource
- SupplierCreditNoteResource

**Fiscal group:**
- FiscalReceiptResource (read-only log with annul action)
- CashRegisterResource (with ShiftResource as RelationManager)

---

## PHASE 4: Field Service + Payments/Reconciliation

### Payment Models

#### Payment
- **Table:** `payments`
- **Attributes:** id, payment_number (unique), document_series_id (FK, nullable), partner_id (FK), direction (PaymentDirection), payment_method (PaymentMethod), currency_code, exchange_rate, amount (decimal(15,2)), amount_in_base_currency (decimal(15,2)), unallocated_amount (decimal(15,2), default:0), cash_register_shift_id (FK, nullable), bank_account_id (FK, nullable), bank_transaction_id (FK, nullable), reference (nullable), paid_at (date), notes (nullable), created_by, timestamps, deleted_at
- **Relationships:** belongsTo Partner, hasMany PaymentAllocation, belongsTo CashRegisterShift (nullable), belongsTo CompanyBankAccount (nullable), belongsTo BankTransaction (nullable)
- **Traits:** SoftDeletes, LogsActivity

#### PaymentAllocation
- **Table:** `payment_allocations`
- **Attributes:** id, payment_id (FK), allocatable_type (string, polymorphic), allocatable_id (bigint), amount (decimal(15,2)), timestamps
- **Note:** allocatable can be Invoice, CreditNote, DebitNote, SupplierInvoice, SupplierCreditNote.

#### InstallmentSchedule
- **Table:** `installment_schedules`
- **Attributes:** id, invoice_id (FK), installment_number (int), due_date (date), amount (decimal(15,2)), amount_paid (decimal(15,2), default:0), status (InstallmentStatus), notes (nullable), timestamps
- **Constraints:** unique(invoice_id, installment_number)

#### CompanyBankAccount
- **Table:** `company_bank_accounts`
- **Attributes:** id, bank_name, iban (unique), bic (nullable), currency_code (default:'BGN'), account_name (nullable), is_default (boolean), is_active (boolean), timestamps, deleted_at
- **Traits:** SoftDeletes

#### BankTransaction
- **Table:** `bank_transactions`
- **Attributes:** id, company_bank_account_id (FK), transaction_type (BankTransactionType), amount (decimal(15,2)), currency_code, transaction_date (date), value_date (date, nullable), counterparty_name (nullable), counterparty_iban (nullable), reference (nullable), description (text, nullable), import_source (BankImportSource), import_batch_id (nullable), reconciliation_status (ReconciliationStatus, default:Unmatched), payment_id (FK, nullable), reconciled_at (nullable), reconciled_by (nullable), raw_data (json, nullable), timestamps
- **Traits:** LogsActivity

### Field Service Models

#### JobSheet
- **Table:** `job_sheets`
- **Attributes:** id, job_number (unique), document_series_id (FK, nullable), partner_id (FK), partner_address_id (FK, nullable), partner_contact_id (FK, nullable), contract_id (FK, nullable), assigned_to (FK tenant_users, nullable), warehouse_id (FK, nullable, technician van), sales_order_id (FK, nullable), status (JobSheetStatus, default:Draft), priority (int, default:0), title, description (text, nullable), scheduled_start (timestamp, nullable), scheduled_end (nullable), actual_start (nullable), actual_end (nullable), site_address (text, nullable), site_contact_name (nullable), site_contact_phone (nullable), customer_signature_path (nullable), internal_notes (nullable), completion_notes (nullable), created_by, timestamps, deleted_at
- **Relationships:** belongsTo Partner, belongsTo Contract (nullable), belongsTo TenantUser (assigned_to), hasMany JobSheetItem, hasMany TimeEntry, hasMany Invoice (via source link)
- **Traits:** SoftDeletes, LogsActivity

#### JobSheetItem
- **Table:** `job_sheet_items`
- **Attributes:** id, job_sheet_id (FK), nomenclature_item_id (FK, nullable), warehouse_id (FK, nullable), description, quantity (decimal(15,4)), unit_price (decimal(19,4)), is_consumed (boolean, default:false), stock_deducted (boolean, default:false), vat_rate_id (FK), line_total (decimal(15,2)), sort_order (int), timestamps
- **Note:** Pending consumption pattern - stock deducted immediately on job confirmation.

#### TimeEntry
- **Table:** `time_entries`
- **Attributes:** id, job_sheet_id (FK), tenant_user_id (FK), entry_type (TimeEntryType), started_at (timestamp), ended_at (nullable), duration_minutes (int, nullable), description (text, nullable), is_billable (boolean, default:true), hourly_rate (decimal(19,4), nullable), timestamps

### Phase 4 Filament Resources

**Finance group:**
- PaymentResource (with allocation workflow)
- CompanyBankAccountResource
- BankTransactionResource (with import action, reconciliation matching action)

**Field Service group:**
- JobSheetResource (with material items Repeater, time entries RelationManager, "Convert to Invoice" action, status workflow actions)

---

## PHASE 5: KPIs/Intelligence + Reports

### Models

#### KpiDefinition
- **Table:** `kpi_definitions`
- **Attributes:** id, name, slug (unique), description, category, calculation_class, unit, target_value, warning_threshold, danger_threshold, is_higher_better (boolean, default:true), is_active, sort_order, timestamps

#### KpiSnapshot
- **Table:** `kpi_snapshots`
- **Attributes:** id, kpi_definition_id (FK), period (KpiPeriod), period_start (date), period_end (date), value (decimal(15,4)), previous_value (nullable), change_percent (nullable), metadata (json, nullable), calculated_at (timestamp), timestamps
- **Constraints:** unique(kpi_definition_id, period, period_start)

#### SavedReport
- **Table:** `saved_reports`
- **Attributes:** id, tenant_user_id (FK), name, report_type, filters (json), columns (json), is_shared (boolean), timestamps

#### NotificationPreference
- **Table:** `notification_preferences`
- **Attributes:** id, tenant_user_id (FK), event_type, channel (string: in_app, email, both), is_enabled (boolean), timestamps
- **Constraints:** unique(tenant_user_id, event_type)

### Phase 5 Filament Resources/Widgets

**Reports & KPIs group:**
- Dashboard widgets: StatsOverviewWidget, SalesChartWidget, StockAlertWidget
- KpiDefinitionResource (admin-only)
- SavedReportResource
- Custom report pages: SalesReport, PurchaseReport, StockValuationReport, AgingReport, VatJournalReport

---

## Navigation Groups (Admin Panel)

| # | Group | Icon | Resources |
|---|-------|------|-----------|
| 1 | Dashboard | Heroicon::OutlinedHome | DashboardPage |
| 2 | CRM | Heroicon::OutlinedUsers | Partner, Contract, Tag |
| 3 | Catalog | Heroicon::OutlinedCube | NomenclatureCategory, NomenclatureItem, UnitOfMeasure, PriceList |
| 4 | Sales | Heroicon::OutlinedShoppingCart | Quote, SalesOrder, Invoice, DeliveryNote, CreditNote, DebitNote |
| 5 | Purchases | Heroicon::OutlinedInboxArrowDown | PurchaseOrder, GoodsReceivedNote, SupplierInvoice, SupplierCreditNote |
| 6 | Warehouse | Heroicon::OutlinedBuildingStorefront | Warehouse, StockLevel, StockTransfer, InventoryCount, InternalConsumptionNote |
| 7 | Field Service | Heroicon::OutlinedWrenchScrewdriver | JobSheet |
| 8 | Finance | Heroicon::OutlinedBankNotes | Payment, CompanyBankAccount, BankTransaction, CashRegister |
| 9 | Fiscal | Heroicon::OutlinedShieldCheck | FiscalReceipt (collapsed by default) |
| 10 | Reports | Heroicon::OutlinedChartBarSquare | KpiDefinition, SavedReport |
| 11 | Settings | Heroicon::OutlinedCog6Tooth | CompanySettings, Currency, VatRate, DocumentSeries, TenantUser, Role (collapsed) |

Use a `NavigationGroup` enum implementing `HasLabel` and `HasIcon` for centralized control.

---

## Role/Permission Matrix

### Roles
| Role | Description |
|------|-------------|
| super-admin | Full access, bypasses all gates |
| admin | Full tenant access, manages settings/users/roles |
| sales-manager | Full sales pipeline, approve discounts |
| sales-agent | Create/edit quotes, orders, invoices |
| warehouse-manager | Full WMS, approve counts/transfers |
| warehouse-operator | Create transfers, counts, movements |
| purchasing-agent | PO and supplier invoice management |
| accountant | Finance, payments, bank reconciliation, read-only sales |
| field-technician | Own job sheets, time entries, consume materials |
| viewer | Read-only all modules except settings |

### Key Permission Rules
- `super-admin` bypasses all checks via `Gate::before()`
- `field-technician` sees only assigned job sheets (scoped query)
- `warehouse-operator` can see stock but NOT sales margins
- `accountant` has read-only on sales/purchases + full finance access
- Delete on confirmed/issued documents restricted to admin only
- Invoice deletion only allowed in Draft status

---

## Key Design Patterns

1. **Cross-DB user reference:** `tenant_users.user_id` references central `users.id` without DB-level FK. Integrity at app level. `TenantUser::centralUser()` queries central connection.

2. **Document totals:** Computed from line items, stored denormalized. A `DocumentTotalsService` recalculates on item changes.

3. **Polymorphic morph map:** Registered in `AppServiceProvider::boot()` with short aliases: `'invoice' => Invoice::class`, etc.

4. **Document conversion (copy + link):** `source_type`/`source_id` on header, `quote_item_id`/`sales_order_item_id` on line items for granular traceability.

5. **Stock reservation:** SalesOrder confirmation increases `quantity_reserved`. Invoice/Delivery converts reservation to deduction.

6. **Multi-currency:** Every financial doc stores `currency_code` + `exchange_rate`. Payments store `amount_in_base_currency` for reporting.

7. **VAT multi-rate:** Each line item has its own `vat_rate_id`. Document `pricing_mode` controls inclusive/exclusive calculation. `VatCalculationService` handles all VAT math.

8. **VAT/Tax engine:** Database-driven `vat_rates` table (country-aware) is source of truth. `ViesValidationService` wraps `laravel-vat-eu-validator` with caching for B2B reverse charge detection. `SyncEuVatRatesCommand` fetches rates from `ibericode/vat-rates` JSON repo. Reverse charge: VIES-validated B2B in different EU country → zero VAT + legal text on footer. OSS: B2C cross-border → apply destination country's rate from DB.

9. **SUPTO:** UNP generated before fiscal print. Annulment = storno receipt. Full ErpNet.FP request/response stored as JSON.

10. **Partial credit notes:** `CreditNoteItem.invoice_item_id` links to specific invoice line. SUM(quantity) validation.

11. **Pending consumption:** Job sheet confirmation deducts stock immediately via StockMovement. Cancellation creates compensating movement.

---

## Verification Plan

### Per-Phase Testing Strategy

**Phase 1:**
- Test tenant creation, subdomain resolution, DB switching
- Test user login, tenant selector, role assignment
- Test Partner CRUD with addresses/contacts RelationManagers
- Test document series number generation (format, reset, atomicity)
- Test VatRate CRUD (country-aware, multiple rate types per country)
- Test Currency CRUD and ExchangeRate sync
- Test `SyncEuVatRatesCommand` populates rates for all EU countries
- Test `ViesValidationService` with valid/invalid EU VAT numbers
- Run `vendor/bin/pint --dirty --format agent`

**Phase 2:**
- Test NomenclatureItem CRUD with category tree navigation
- Test bundle component management (prevent nesting)
- Test StockMovement creation and StockLevel cache updates
- Test StockTransfer workflow (Draft -> InTransit -> PartiallyReceived -> Received)
- Test InventoryCount with adjustment generation
- Test InternalConsumptionNote stock deduction

**Phase 3:**
- Test Quote -> SalesOrder -> Invoice conversion chain (data copying, link preservation)
- Test partial CreditNote creation (quantity validation against parent invoice)
- Test `VatCalculationService`: inclusive/exclusive pricing, multi-rate per document
- Test reverse charge: VIES-validated EU B2B partner → zero VAT + legal text
- Test OSS: B2C cross-border → destination country rate applied
- Test multi-VAT-rate invoice (standard + reduced rows, correct totals)
- Test SUPTO flow: UNP generation, fiscal receipt creation, annulment
- Test proforma vs tax invoice distinction
- Test advance payment invoice flow

**Phase 4:**
- Test Payment allocation across multiple invoices
- Test installment schedule with partial payments
- Test bank transaction import (CSV) and reconciliation matching
- Test JobSheet workflow with material consumption and stock deduction
- Test time entry (manual + timer)
- Test 5-day clock alert for completed-but-not-invoiced jobs

**Phase 5:**
- Test KPI calculation jobs
- Test dashboard widgets with real data
- Test saved report persistence and sharing

### Integration Tests
- End-to-end: Create partner -> Create quote -> Convert to order -> Convert to invoice -> Record payment -> Print fiscal receipt
- Stock flow: Purchase order -> GRN -> Stock increase -> Sale -> Invoice -> Stock decrease -> Verify StockLevel
- Multi-currency: Create invoice in EUR, record payment in BGN, verify exchange rate conversion
