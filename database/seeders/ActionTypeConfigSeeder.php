<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ActionTypeConfig;
use Illuminate\Database\Seeder;

class ActionTypeConfigSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'type' => 'delete_series',
                'label' => 'Delete series from Sonarr',
                'description' => 'Remove a series from Sonarr when it is deleted from Emby.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'delete_movie',
                'label' => 'Delete movie from Radarr',
                'description' => 'Remove a movie from Radarr when it is deleted from Emby.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'cleanup_jellyseerr_request',
                'label' => 'Clean up Jellyseerr request',
                'description' => 'Delete the matching Jellyseerr request when media is removed.',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
            [
                'type' => 'emby_library_scan',
                'label' => 'Trigger Emby library scan',
                'description' => 'Ask Emby to refresh its library after a download completes.',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
        ];

        foreach ($types as $type) {
            ActionTypeConfig::updateOrCreate(['type' => $type['type']], $type);
        }
    }
}
