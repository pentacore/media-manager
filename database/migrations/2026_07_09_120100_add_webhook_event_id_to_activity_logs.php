<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $blueprint): void {
            // Links handler-written activity back to its originating webhook.
            // nullOnDelete so activity survives capture-off event trimming.
            $blueprint->foreignId('webhook_event_id')
                ->nullable()
                ->after('service_connection_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $blueprint): void {
            $blueprint->dropConstrainedForeignId('webhook_event_id');
        });
    }
};
