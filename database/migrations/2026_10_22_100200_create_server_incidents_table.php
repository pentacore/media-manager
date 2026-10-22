<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_incidents', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('server_id')->constrained()->cascadeOnDelete();
            $blueprint->text('key');
            $blueprint->string('severity', 16);
            $blueprint->string('status', 16)->default('open');
            $blueprint->string('title', 255);
            $blueprint->text('detail')->nullable();
            $blueprint->jsonb('context')->default('{}');
            $blueprint->timestamp('opened_at');
            $blueprint->timestamp('last_seen_at');
            $blueprint->unsignedSmallInteger('clear_streak')->default(0);
            $blueprint->timestamp('escalated_at')->nullable();
            $blueprint->timestamp('resolved_at')->nullable();
            $blueprint->string('notified_severity', 16)->nullable();
            $blueprint->timestamps();

            $blueprint->index(['status', 'opened_at']);
            $blueprint->index(['server_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX server_incidents_open_key_unique ON server_incidents (server_id, key) WHERE status = 'open'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS server_incidents_open_key_unique');
        Schema::dropIfExists('server_incidents');
    }
};
