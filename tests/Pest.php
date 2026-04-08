<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| DatabaseTruncation truncates tables between tests without wrapping in
| a transaction — required because stancl's CREATE DATABASE DDL cannot
| run inside a PostgreSQL transaction block (which RefreshDatabase uses).
|
*/

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->afterEach(function () {
        // Drop each tenant's PostgreSQL database before the central table is truncated.
        // Without this step, orphaned tenant databases accumulate in PostgreSQL.
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
