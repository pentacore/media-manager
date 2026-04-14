<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_connections', function (Blueprint $blueprint): void {
            $blueprint->string('health_status')->nullable()->after('is_active');
            $blueprint->string('latest_version')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('service_connections', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['health_status', 'latest_version']);
        });
    }
};
