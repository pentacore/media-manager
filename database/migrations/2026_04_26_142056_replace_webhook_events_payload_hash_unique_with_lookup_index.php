<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Swap the global unique constraint on (connection, event_type, payload_hash)
 * for a non-unique lookup index. The unique constraint silently dropped any
 * legitimate identical payload sent again later (e.g. a re-grab of the same
 * release hours apart). Dedupe now happens in WebhookController via a 5-minute
 * time-bounded SELECT, so the column still needs an index for that lookup but
 * must not block historical re-occurrences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $blueprint): void {
            $blueprint->dropUnique('webhook_events_connection_event_payload_unique');
            $blueprint->index(
                ['service_connection_id', 'event_type', 'payload_hash'],
                'webhook_events_dedupe_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('webhook_events_dedupe_lookup_index');
            $blueprint->unique(
                ['service_connection_id', 'event_type', 'payload_hash'],
                'webhook_events_connection_event_payload_unique'
            );
        });
    }
};
