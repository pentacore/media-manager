<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backs the Prometheus webhook-lag gauge
     * (`mediamanager_webhook_oldest_pending_age_seconds`), which does
     * `WHERE processed_at IS NULL ... MIN(created_at)` on every scrape. The
     * table already has a plain `created_at` index for chronological
     * listing, but that covers every row; a partial index keyed on the
     * selective predicate (unprocessed rows are the minority once the app
     * has run a while) keeps the scrape query cheap as processed history
     * grows, without indexing rows the gauge never reads. `WHERE` syntax
     * here is Postgres/SQLite compatible, but the query builder can't
     * express a partial index, hence the raw statement.
     */
    public function up(): void
    {
        DB::statement('
            CREATE INDEX webhook_events_pending_created_at_index
            ON webhook_events (created_at)
            WHERE processed_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS webhook_events_pending_created_at_index');
    }
};
