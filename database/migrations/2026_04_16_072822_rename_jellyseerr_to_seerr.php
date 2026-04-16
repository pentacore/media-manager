<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Service connection type
        DB::table('service_connections')->where('type', 'jellyseerr')->update(['type' => 'seerr']);

        // Action type configs
        DB::table('action_type_configs')
            ->where('type', 'cleanup_jellyseerr_request')
            ->update([
                'type' => 'cleanup_seerr_request',
                'label' => 'Clean up Seerr request',
                'description' => 'Delete the matching Seerr request when media is removed.',
            ]);

        // Existing ActionRequests — source/target service strings and type
        DB::table('action_requests')->where('source_service', 'jellyseerr')->update(['source_service' => 'seerr']);
        DB::table('action_requests')->where('target_service', 'jellyseerr')->update(['target_service' => 'seerr']);
        DB::table('action_requests')->where('type', 'cleanup_jellyseerr_request')->update(['type' => 'cleanup_seerr_request']);
    }

    public function down(): void
    {
        DB::table('service_connections')->where('type', 'seerr')->update(['type' => 'jellyseerr']);
        DB::table('action_type_configs')
            ->where('type', 'cleanup_seerr_request')
            ->update([
                'type' => 'cleanup_jellyseerr_request',
                'label' => 'Clean up Jellyseerr request',
                'description' => 'Delete the matching Jellyseerr request when media is removed.',
            ]);
        DB::table('action_requests')->where('source_service', 'seerr')->update(['source_service' => 'jellyseerr']);
        DB::table('action_requests')->where('target_service', 'seerr')->update(['target_service' => 'jellyseerr']);
        DB::table('action_requests')->where('type', 'cleanup_seerr_request')->update(['type' => 'cleanup_jellyseerr_request']);
    }
};
