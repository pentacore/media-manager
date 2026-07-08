<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user ntfy topic. The ntfy server URL and auth token are global
     * config (services.ntfy); the topic decides where a user's pushes go.
     * Null/empty means ntfy is off for that user regardless of their
     * notification-preference toggles.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $blueprint): void {
            $blueprint->string('ntfy_topic')->nullable()->after('preferences');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('ntfy_topic');
        });
    }
};
