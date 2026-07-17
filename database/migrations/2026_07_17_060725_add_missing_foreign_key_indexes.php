<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres does not auto-index foreign key columns (unlike MySQL/InnoDB), so
 * these FK columns had no index at all. activity_logs.webhook_event_id is the
 * hot one: the webhook log page runs a correlated withCount() subquery per
 * row against it, and every webhook_events delete (capture disabled) triggers
 * an ON DELETE SET NULL scan over activity_logs. The rest back frequent
 * per-user / per-connection lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $blueprint): void {
            $blueprint->index('webhook_event_id');
        });

        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->index('user_id');
        });

        Schema::table('media_replacement_attempts', function (Blueprint $blueprint): void {
            $blueprint->index('service_connection_id');
        });

        Schema::table('emby_user_links', function (Blueprint $blueprint): void {
            $blueprint->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['webhook_event_id']);
        });

        Schema::table('ai_usage_records', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['user_id']);
        });

        Schema::table('media_replacement_attempts', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['service_connection_id']);
        });

        Schema::table('emby_user_links', function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['user_id']);
        });
    }
};
