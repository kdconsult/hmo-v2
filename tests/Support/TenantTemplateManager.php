<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Testing\TemplatedPostgreSQLDatabaseManager;
use App\Models\CompanySettings;
use App\Models\Warehouse;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\EuCountryVatRatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UnitSeeder;
use Database\Seeders\VatLegalReferenceSeeder;
use Database\Seeders\VatRateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Manages the shared pre-seeded PostgreSQL template database used by all tests.
 *
 * On the first test in each worker process, ensures that `hmo_test_tenant_template`
 * exists and contains all current tenant migrations + seed data. When anything
 * changes (migration files, seeder files), the hash changes and the template is
 * automatically rebuilt.
 *
 * Uses a PostgreSQL advisory lock so parallel workers serialise around the
 * single build — all workers after the first one simply verify the template is
 * ready and continue.
 */
class TenantTemplateManager
{
    private const TEMPLATE_DB = TemplatedPostgreSQLDatabaseManager::TEMPLATE_DB;

    /** PostgreSQL advisory lock key — arbitrary large int, must be unique per app */
    private const LOCK_KEY = 987_654_321;

    private const TEMP_CONNECTION = 'tenant_template';

    private static bool $ensured = false;

    /**
     * Call once per worker process. Subsequent calls are no-ops (static flag).
     */
    public static function ensureOnce(): void
    {
        if (self::$ensured) {
            return;
        }

        self::$ensured = true;
        self::ensure();
    }

    /**
     * Acquire an advisory lock, check if the template is valid, recreate if not.
     */
    public static function ensure(): void
    {
        DB::statement('SELECT pg_advisory_lock('.self::LOCK_KEY.')');

        try {
            if (self::templateIsValid()) {
                return;
            }

            self::recreateTemplate();
        } finally {
            DB::statement('SELECT pg_advisory_unlock('.self::LOCK_KEY.')');
        }
    }

    // ─── private helpers ───────────────────────────────────────────────────────

    private static function templateIsValid(): bool
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [self::TEMPLATE_DB],
        );

        if (! $exists) {
            return false;
        }

        $hashFile = self::hashFilePath();

        return file_exists($hashFile)
            && file_get_contents($hashFile) === self::currentHash();
    }

    private static function recreateTemplate(): void
    {
        // Terminate any stale connections to the old template (safety net).
        DB::select(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [self::TEMPLATE_DB],
        );

        DB::statement('DROP DATABASE IF EXISTS "'.self::TEMPLATE_DB.'"');
        DB::statement('CREATE DATABASE "'.self::TEMPLATE_DB.'" TEMPLATE template0');

        self::registerTempConnection();

        // Run all tenant migrations against the template DB.
        Artisan::call('migrate', [
            '--database' => self::TEMP_CONNECTION,
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
            '--force' => true,
        ]);

        // Seed + create base records in the template DB.
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::TEMP_CONNECTION);

        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            app(RolesAndPermissionsSeeder::class)->run();
            app(CurrencySeeder::class)->run();
            app(VatRateSeeder::class)->run();
            app(UnitSeeder::class)->run();
            app(EuCountryVatRatesSeeder::class)->run();
            app(VatLegalReferenceSeeder::class)->run();

            Warehouse::firstOrCreate(
                ['code' => 'MAIN'],
                ['name' => 'Main Warehouse', 'is_default' => true, 'is_active' => true],
            );

            CompanySettings::set('localization', 'locale_en', '1');

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            DB::setDefaultConnection($original);
            DB::purge(self::TEMP_CONNECTION);
        }

        self::storeHash();
    }

    private static function registerTempConnection(): void
    {
        $base = config('database.connections.pgsql');
        config(['database.connections.'.self::TEMP_CONNECTION => array_merge($base, [
            'database' => self::TEMPLATE_DB,
        ])]);
        DB::purge(self::TEMP_CONNECTION);
    }

    // ─── hash helpers ──────────────────────────────────────────────────────────

    private static function currentHash(): string
    {
        $migrationFiles = glob(database_path('migrations/tenant/*.php')) ?: [];
        sort($migrationFiles);

        $seederFiles = [
            database_path('seeders/RolesAndPermissionsSeeder.php'),
            database_path('seeders/CurrencySeeder.php'),
            database_path('seeders/VatRateSeeder.php'),
            database_path('seeders/UnitSeeder.php'),
            database_path('seeders/EuCountryVatRatesSeeder.php'),
            database_path('seeders/VatLegalReferenceSeeder.php'),
            app_path('Services/TenantOnboardingService.php'),
        ];

        $combined = '';
        foreach (array_merge($migrationFiles, $seederFiles) as $file) {
            if (file_exists($file)) {
                $combined .= md5_file($file);
            }
        }

        return md5($combined);
    }

    private static function hashFilePath(): string
    {
        return storage_path('testing/tenant_template.hash');
    }

    private static function storeHash(): void
    {
        $dir = dirname(self::hashFilePath());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(self::hashFilePath(), self::currentHash());
    }
}
