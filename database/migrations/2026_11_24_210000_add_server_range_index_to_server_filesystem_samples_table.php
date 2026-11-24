<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Show page charts read one server's samples at one resolution over a time range, across every mount. The unique
 * key puts `mount` before `recorded_at`, so it cannot bound that range; this index can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_filesystem_samples', function (Blueprint $blueprint): void {
            $blueprint->index(['server_id', 'resolution', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('server_filesystem_samples', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['server_id', 'resolution', 'recorded_at']);
        });
    }
};
