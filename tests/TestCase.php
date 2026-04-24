<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Drop all database types when refreshing the test database.
     *
     * Prevents Postgres `pg_type_typname_nsp_index` duplicate-key errors
     * when a previous test run was interrupted mid-migration and left
     * custom/composite types behind.
     */
    protected bool $dropTypes = true;

    /**
     * Drop all database views when refreshing the test database.
     *
     * Future-proofing: if we add DB views later, this keeps `migrate:fresh`
     * idempotent after interrupted runs. No-op on drivers without views.
     */
    protected bool $dropViews = true;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? sprintf('Fortify feature [%s] is not enabled.', $feature));
        }
    }
}
