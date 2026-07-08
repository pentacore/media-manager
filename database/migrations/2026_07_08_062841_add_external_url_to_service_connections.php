<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds an optional externally-reachable URL per service connection.
     * User-facing "open in service" links prefer it over `url`, which
     * often points at an internal Docker/LAN address the user's browser
     * cannot reach. API clients and health checks keep using `url`.
     */
    public function up(): void
    {
        Schema::table('service_connections', function (Blueprint $blueprint): void {
            $blueprint->string('external_url')->nullable()->after('url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_connections', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('external_url');
        });
    }
};
