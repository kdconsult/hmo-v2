# Phase 1 Tasks Checklist

## Task 1.1 — Foundation: PostgreSQL + Packages + Config ✅
- [x] Switched .env from SQLite to PostgreSQL
- [x] docker-compose.yml with PostgreSQL 17 container
- [x] Installed: stancl/tenancy, spatie/laravel-permission, spatie/laravel-activitylog, barryvdh/laravel-dompdf, danielebarbaro/laravel-vat-eu-validator
- [x] Published tenancy config, permission config, activitylog migrations
- [x] config/tenancy.php: tenant_model, domain_model, central_domains configured
- [x] TenancyServiceProvider registered in bootstrap/providers.php

## Task 1.2 — Central Database: Tenant, Domain, User ✅
- [x] Tenant migration with all HMO business columns
- [x] Domain migration (stancl default)
- [x] Add HMO columns to users table (avatar_path, locale, is_landlord, last_login_at)
- [x] Tenant model: TenantWithDatabase, HasDatabase, HasDomains, HasFactory
- [x] Domain model
- [x] User model: CentralConnection trait, FilamentUser, canAccessPanel()
- [x] TenantFactory, UserFactory (with landlord() state)

## Task 1.3 — Panels & Multi-Tenant Auth ✅
- [x] Landlord panel (slate color, no tenancy middleware)
- [x] Admin panel with stancl middleware as isPersistent:true
- [x] User::canAccessPanel() — landlord checks is_landlord, admin checks TenantUser exists

## Task 1.4 — All Enums (All Phases) ✅
- [x] 28 enum files in app/Enums/
- [x] All implement HasLabel; badge enums use HasColor/HasIcon with Heroicon values
- [x] NavigationGroup enum for all 11 nav groups

## Task 1.5 — Tenant Core Models: Settings & Finance ✅
- [x] All tenant migrations in database/migrations/tenant/
- [x] Cache table migration (BEFORE permission tables)
- [x] TenantUser model (HasRoles, SoftDeletes, centralUser())
- [x] CompanySettings model (static get/set helpers)
- [x] Currency model
- [x] ExchangeRate model
- [x] VatRate model (scopeActive, scopeForCountry)
- [x] DocumentSeries model (generateNumber() with DB lock)
- [x] Factories for all models

## Task 1.6 — Tenant CRM Models: Partners & Contracts ✅
- [x] Tag model with morphedByMany
- [x] Tags + taggables migrations (taggables AFTER tags in timestamp order)
- [x] Partner model (LogsActivity with correct namespace, SoftDeletes, scopeActive)
- [x] PartnerAddress, PartnerContact, PartnerBankAccount models
- [x] Contract model
- [x] Morph map registered in AppServiceProvider
- [x] All factories

## Task 1.7 — RBAC: Roles, Permissions & Policies ✅
- [x] RolesAndPermissionsSeeder with 10 roles
- [x] Permissions: view_any/view/create/update/delete for 10 models
- [x] Gate::before for super-admin bypass in AppServiceProvider
- [x] Policies for: Partner, Contract, Currency, VatRate, DocumentSeries, TenantUser, Tag, CompanySettings, Role

## Task 1.8 — Landlord Panel Filament Resources ✅
- [x] TenantResource (sections: Company Info, Localization, Subscription)
- [x] DomainsRelationManager registered in TenantResource
- [x] UserResource for central users
- [x] Soft delete support with TrashedFilter

## Task 1.9 — Admin Panel: Settings Group Resources ✅
- [x] CompanySettingsPage (tabbed: General, Invoicing, Fiscal)
- [x] CurrencyResource with ExchangeRatesRelationManager
- [x] VatRateResource (country_code, type, effective dates)
- [x] DocumentSeriesResource (full format preview)
- [x] TenantUserResource with role assignment
- [x] RoleResource with grouped permission checkboxes
- [x] All under navigationGroup = 'Settings'

## Task 1.10 — Admin Panel: CRM Group Resources ✅
- [x] PartnerResource with Addresses/Contacts/BankAccounts RelationManagers
- [x] Table filters: type, is_customer, is_supplier, is_active, trashed
- [x] ContractResource (sections: Contract Details, Terms, Notes)
- [x] TagResource (simple inline CRUD)
- [x] All under navigationGroup = 'CRM'

## Task 1.11 — Services, Seeders & Tests ✅
- [x] VatCalculationService (fromNet, fromGross, calculate, calculateDocument)
- [x] ViesValidationService (SOAP VIES with 24h cache)
- [x] SyncEuVatRatesCommand (hmo:sync-eu-vat-rates)
- [x] CurrencySeeder (BGN, EUR, USD, GBP + EU currencies)
- [x] VatRateSeeder (BG rates: 20%, 9%, 0%)
- [x] DatabaseSeeder (landlord user + demo tenant + tenant-admin)
- [x] Unit tests: VatCalculationServiceTest (7 tests, all pass)
- [x] Feature tests: DocumentSeriesTest
- [x] Pint formatting: all files clean

## Task 1.12 — Tenant Lifecycle Management ✅
- [x] TenantStatus enum (Active, Suspended, MarkedForDeletion, ScheduledForDeletion) with canTransitionTo()
- [x] Migration: add lifecycle columns to tenants (status, deactivated_at, marked_for_deletion_at, scheduled_for_deletion_at, deletion_scheduled_for, deactivation_reason, deactivated_by)
- [x] Tenant model: $casts, scopes (active/suspended/markedForDeletion/scheduledForDeletion/dueForDeletion), lifecycle methods (suspend/markForDeletion/scheduleForDeletion/reactivate), deactivatedBy relation
- [x] TenantFactory: suspended(), markedForDeletion(), scheduledForDeletion() states
- [x] TenantPolicy: delete() always returns false, lifecycle actions gated by status
- [x] TenantResource: removed broken SoftDeletingScope override and stale imports
- [x] TenantsTable: removed TrashedFilter/ForceDelete/Restore/Delete bulk actions; added status badge column, SelectFilter, lifecycle row actions
- [x] TenantForm: Lifecycle section (read-only, edit-only) showing all status fields
- [x] TenantInfolist: Lifecycle section with all status entries
- [x] TenancyServiceProvider: DeletingTenant safety guard — blocks deletion unless status=ScheduledForDeletion AND deletion_scheduled_for is past
- [x] DeleteScheduledTenantsCommand (hmo:delete-scheduled-tenants) — scheduled daily
- [x] Mail stubs: TenantSuspendedMail, TenantMarkedForDeletionMail, TenantScheduledForDeletionMail, TenantDeletedMail, TenantReactivatedMail
- [x] TenantLifecycleTest: 17 tests covering all transitions, scopes, policy, safety guard, command

## Task 1.13 — Plans & Subscriptions ✅
- [x] SubscriptionStatus enum (Trial, Active, Expired, Cancelled) with isAccessible()
- [x] Plan model with isFree() helper
- [x] plans migration (name, slug, price, billing_period, max_users, max_documents, is_active, sort_order)
- [x] add_plan_subscription_fields_to_tenants migration (plan_id, subscription_status, trial_ends_at, subscription_ends_at)
- [x] PlanSeeder (Free, Starter, Professional plans)
- [x] PlanLimitService (can_add_user, can_add_document checks)
- [x] PlanResource in Landlord panel (CRUD with PlanForm, PlansTable)
- [x] CheckTrialExpirations command (hmo:check-trial-expirations) — scheduled daily
- [x] CheckSubscriptionExpirations command (hmo:check-subscription-expirations) — scheduled daily
- [x] EnsureActiveSubscription middleware (blocks suspended/expired tenants)
- [x] SubscriptionPlansTest covering plan limit logic

## Task 1.14 — Self-Registration & Tenant Onboarding ✅
- [x] TenantOnboardingService: seeds tenant DB (roles, currencies, vat rates), creates TenantUser as super-admin
- [x] RegisterTenant Livewire component (3-step wizard: Account → Organization → Plan)
- [x] EuCountries support class (EU country list with currency, timezone, locale, VAT prefix)
- [x] Guest layout (resources/views/components/layouts/guest.blade.php)
- [x] Register-tenant Blade view with step indicator
- [x] /register route wired to RegisterTenant
- [x] WelcomeTenant, TrialExpiringSoon, TrialExpired, ProformaInvoice mail classes
- [x] UsersRelationManager on TenantResource (Landlord panel)
- [x] CreateTenant page updated: TenantOnboardingService called after admin creates tenant
- [x] RegisterTenantTest: 12 feature tests covering all steps, validation, submit flow

## Task 1.15 — Auto-Generated Tenant Subdomain ✅
- [x] TenantSlugGenerator support class: 40 adjectives × 48 nouns → adjective-noun format (e.g. bright-harbor)
- [x] Tenant::generateUniqueSlug(): tries adjective-noun, falls back to adjective-noun-NNN on collision
- [x] Removed slug field from self-registration form (user no longer chooses subdomain)
- [x] TenantForm: slug hidden on create (auto-generated), visible/editable on edit for landlord admins
- [x] CreateTenant page: slug injected in mutateFormDataBeforeCreate()
- [x] TenantSlugGeneratorTest (Unit): format, valid DNS chars, variance
- [x] TenantSlugTest (Feature): pattern, uniqueness, retry on collision
- [x] RegisterTenantTest updated: removed slug-input tests, asserts auto-generated slug pattern
