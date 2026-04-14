<?php

declare(strict_types=1);

namespace App\Database\Testing;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;

/**
 * Uses CREATE DATABASE ... TEMPLATE so every test tenant DB is cloned from
 * a pre-migrated, pre-seeded template instead of running migrations + seeders
 * from scratch. Reduces per-test DB setup from ~40 migrations + 5 seeders to
 * a near-instant filesystem copy.
 */
class TemplatedPostgreSQLDatabaseManager extends PostgreSQLDatabaseManager
{
    public const TEMPLATE_DB = 'hmo_test_tenant_template';

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement(sprintf(
            'CREATE DATABASE "%s" TEMPLATE "%s"',
            $tenant->database()->getName(),
            self::TEMPLATE_DB,
        ));
    }
}
