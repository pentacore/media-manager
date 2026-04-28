<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_metrics', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('service_connection_id')
                ->constrained()
                ->cascadeOnDelete();
            // 'healthy' | 'unhealthy' | 'unknown' — kept as a string so
            // future intermediate states (e.g. 'degraded') can be added
            // without a schema change.
            $blueprint->string('status', 16);
            // Round-trip ms for the ping. Null when the call errored before
            // a response (e.g. ConnectionException) or for status='unknown'.
            $blueprint->unsignedInteger('latency_ms')->nullable();
            // Optional message captured at write time so the UI can render
            // an incident timeline without joining the connection table.
            $blueprint->string('message', 255)->nullable();
            $blueprint->timestamp('recorded_at')->index();

            // Lookups always filter by connection + time range, so a
            // composite index keeps the dashboard sparklines and the
            // service-health 60-min strip cheap.
            $blueprint->index(['service_connection_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_metrics');
    }
};
