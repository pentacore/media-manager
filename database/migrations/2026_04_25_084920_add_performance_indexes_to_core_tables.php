<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_connections', function (Blueprint $blueprint): void {
            $blueprint->index(['type', 'is_active'], 'service_connections_type_active_index');
        });

        Schema::table('webhook_events', function (Blueprint $blueprint): void {
            $blueprint->index('created_at', 'webhook_events_created_at_index');
            $blueprint->index(['service_connection_id', 'created_at'], 'webhook_events_connection_created_index');
        });

        Schema::table('action_requests', function (Blueprint $blueprint): void {
            $blueprint->index(['status', 'created_at'], 'action_requests_status_created_index');
            $blueprint->index('created_at', 'action_requests_created_at_index');
            $blueprint->index('webhook_event_id', 'action_requests_webhook_event_index');
            $blueprint->index('approved_by', 'action_requests_approved_by_index');
        });

        Schema::table('activity_logs', function (Blueprint $blueprint): void {
            $blueprint->index('created_at', 'activity_logs_created_at_index');
            $blueprint->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
            $blueprint->index(['service_connection_id', 'created_at'], 'activity_logs_connection_created_index');
        });

        Schema::table('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->index('created_at', 'emby_activities_created_at_index');
            $blueprint->index(['media_type', 'created_at'], 'emby_activities_media_type_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emby_activities', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('emby_activities_media_type_created_index');
            $blueprint->dropIndex('emby_activities_created_at_index');
        });

        Schema::table('activity_logs', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('activity_logs_connection_created_index');
            $blueprint->dropIndex('activity_logs_user_created_index');
            $blueprint->dropIndex('activity_logs_created_at_index');
        });

        Schema::table('action_requests', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('action_requests_approved_by_index');
            $blueprint->dropIndex('action_requests_webhook_event_index');
            $blueprint->dropIndex('action_requests_created_at_index');
            $blueprint->dropIndex('action_requests_status_created_index');
        });

        Schema::table('webhook_events', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('webhook_events_connection_created_index');
            $blueprint->dropIndex('webhook_events_created_at_index');
        });

        Schema::table('service_connections', function (Blueprint $blueprint): void {
            $blueprint->dropIndex('service_connections_type_active_index');
        });
    }
};
