<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in flag for the Web push channel, mirroring `webhook`.
     */
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $blueprint): void {
            $blueprint->boolean('webpush')->default(false)->after('webhook');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $blueprint): void {
            $blueprint->dropColumn('webpush');
        });
    }
};
