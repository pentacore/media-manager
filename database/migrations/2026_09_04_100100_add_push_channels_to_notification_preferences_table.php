<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in flags for the three new push channels, mirroring `ntfy`.
     */
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $blueprint): void {
            $blueprint->boolean('discord')->default(false)->after('ntfy');
            $blueprint->boolean('telegram')->default(false)->after('discord');
            $blueprint->boolean('webhook')->default(false)->after('telegram');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['discord', 'telegram', 'webhook']);
        });
    }
};
