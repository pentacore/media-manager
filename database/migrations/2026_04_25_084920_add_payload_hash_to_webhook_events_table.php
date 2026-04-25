<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $blueprint): void {
            $blueprint->string('payload_hash', 64)->nullable()->after('payload');
            $blueprint->unique(
                ['service_connection_id', 'event_type', 'payload_hash'],
                'webhook_events_connection_event_payload_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $blueprint): void {
            $blueprint->dropUnique('webhook_events_connection_event_payload_unique');
            $blueprint->dropColumn('payload_hash');
        });
    }
};
