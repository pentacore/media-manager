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
                'type' => 'add_series',
                'label' => 'Add series to Sonarr',
                'description' => 'Add a series to Sonarr (AI-initiated).',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'monitor_series',
                'label' => 'Toggle Sonarr series monitoring',
                'description' => 'Set whether Sonarr monitors a series for new episodes (AI-initiated).',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
            [
                'type' => 'set_series_quality_profile',
                'label' => 'Change Sonarr series quality profile',
                'description' => 'Change the quality profile assigned to a series in Sonarr (AI-initiated).',
                'requires_approval' => false,
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
                'type' => 'add_movie',
                'label' => 'Add movie to Radarr',
                'description' => 'Add a movie to Radarr (AI-initiated).',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'monitor_movie',
                'label' => 'Toggle Radarr movie monitoring',
                'description' => 'Set whether Radarr monitors a movie (AI-initiated).',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
            [
                'type' => 'set_movie_quality_profile',
                'label' => 'Change Radarr movie quality profile',
                'description' => 'Change the quality profile assigned to a movie in Radarr (AI-initiated).',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
            [
                'type' => 'cleanup_seerr_request',
                'label' => 'Clean up Seerr request',
                'description' => 'Delete the matching Seerr request when media is removed.',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
            [
                'type' => 'approve_seerr_request',
                'label' => 'Approve Seerr request',
                'description' => 'Approve a pending Seerr media request (AI-initiated).',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
            [
                'type' => 'decline_seerr_request',
                'label' => 'Decline Seerr request',
                'description' => 'Decline a pending Seerr media request (AI-initiated).',
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
            [
                'type' => 'resolve_manual_import',
                'label' => 'Resolve stuck Sonarr/Radarr import',
                'description' => 'Trigger a manual import for a download stuck on "manual interaction required" (DecisionAgent-initiated). Defaults to requiring approval; partial/unmapped imports always require approval regardless.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'remove_stuck_download',
                'label' => 'Remove stuck Sonarr/Radarr download',
                'description' => 'Remove a stuck download from the Sonarr/Radarr queue without blocklisting (DecisionAgent-initiated) — e.g. when a release is not an upgrade. Defaults to requiring approval; deletes the downloaded data.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'replace_media_file',
                'label' => 'Replace media file with better subtitles',
                'description' => 'Grab a ranked replacement release for an imported Sonarr episode or Radarr movie, then delete the reviewed file only after the grab is accepted (AI-initiated). Defaults to requiring approval.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_download_best',
                'label' => 'Download the best subtitle in Bazarr',
                'description' => 'Ask Bazarr to download the best available subtitle for the approved media file and language.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_download_exact',
                'label' => 'Download a selected Bazarr subtitle',
                'description' => 'Download the specifically approved subtitle candidate to the approved media file.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_upload_subtitle',
                'label' => 'Upload a subtitle to Bazarr',
                'description' => 'Attach a privately staged subtitle file to the approved episode or movie.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_delete_subtitle',
                'label' => 'Delete a subtitle in Bazarr',
                'description' => 'Delete the specifically approved external subtitle file from the media target.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_sync_subtitle',
                'label' => 'Synchronize a subtitle in Bazarr',
                'description' => 'Run Bazarr synchronization against the specifically approved subtitle file.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_translate_subtitle',
                'label' => 'Translate a subtitle in Bazarr',
                'description' => 'Run Bazarr translation against the specifically approved subtitle file.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_modify_subtitle',
                'label' => 'Modify a subtitle in Bazarr',
                'description' => 'Run an approved Bazarr subtitle modification tool against one subtitle file.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_scan_media',
                'label' => 'Scan media for subtitles in Bazarr',
                'description' => 'Ask Bazarr to rescan or search the approved series or movie for subtitle files.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'bazarr_run_task',
                'label' => 'Run a Bazarr maintenance task',
                'description' => 'Run one specifically approved Bazarr system task.',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'whisparr_add_item',
                'label' => 'Add item to Whisparr',
                'description' => 'Add a series or movie to Whisparr (AI-initiated).',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'whisparr_delete_item',
                'label' => 'Delete item from Whisparr',
                'description' => 'Remove a series or movie from Whisparr, including files (AI-initiated).',
                'requires_approval' => true,
                'is_enabled' => true,
            ],
            [
                'type' => 'whisparr_monitor_item',
                'label' => 'Toggle Whisparr item monitoring',
                'description' => 'Set whether Whisparr monitors an item for new releases (AI-initiated).',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
            [
                'type' => 'whisparr_set_quality_profile',
                'label' => 'Change Whisparr quality profile',
                'description' => 'Change the quality profile assigned to an item in Whisparr (AI-initiated).',
                'requires_approval' => false,
                'is_enabled' => true,
            ],
        ];

        foreach ($types as $type) {
            // firstOrCreate is race-safe: on a unique-constraint collision with a
            // concurrent replica (the entrypoint's env-driven migration path can run
            // on several), Laravel's createOrFirst re-reads the winner and rethrows
            // only when the re-read finds nothing — losing the race is tolerated,
            // real failures stay loud.
            $config = ActionTypeConfig::query()->firstOrCreate(['type' => $type['type']], $type);

            if (! $config->wasRecentlyCreated) {
                // requires_approval / is_enabled belong to the admin via Action Rules —
                // a re-run must never reset them. Only the display copy self-heals.
                $config->update([
                    'label' => $type['label'],
                    'description' => $type['description'],
                ]);
            }
        }
    }
}
