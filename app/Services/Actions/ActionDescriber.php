<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ServiceType;

/**
 * The single source of approval-card wording for the arr, Seerr, Emby and
 * download action types. Chat tools, webhook handlers and the decision agent
 * all describe the same types, so the title, effect sentence and target facts
 * live here; each producer only adds its "why" via ActionDescription::because()
 * and any trigger facts. Payload flags are coerced exactly as the executors
 * coerce them, so the approval card never contradicts what runs.
 */
final readonly class ActionDescriber
{
    /** @var list<string> */
    public const array TYPES = [
        'delete_series', 'delete_movie', 'whisparr_delete_item',
        'add_series', 'add_movie', 'whisparr_add_item',
        'monitor_series', 'monitor_movie', 'whisparr_monitor_item',
        'set_series_quality_profile', 'set_movie_quality_profile', 'whisparr_set_quality_profile',
        'approve_seerr_request', 'decline_seerr_request', 'cleanup_seerr_request',
        'emby_library_scan', 'remove_stuck_download', 'resolve_manual_import',
    ];

    public function __construct(private ActionTargets $actionTargets) {}

    /**
     * @param  array<string, mixed>  $payload  The action payload; its service_connection_id pins the connection like the executors do.
     */
    public function describe(string $type, array $payload, ?string $fallbackName = null, bool $fallbackVerified = false): ActionDescription
    {
        return match ($type) {
            'delete_series' => $this->delete($this->actionTargets->sonarrSeries($this->id($type, $payload, 'sonarr_series_id'), $payload, $fallbackName, $fallbackVerified), 'Sonarr', $payload),
            'delete_movie' => $this->delete($this->actionTargets->radarrMovie($this->id($type, $payload, 'radarr_movie_id'), $payload, $fallbackName, $fallbackVerified), 'Radarr', $payload),
            'whisparr_delete_item' => $this->delete($this->actionTargets->whisparrItem($this->id($type, $payload, 'whisparr_item_id'), $payload, $fallbackName), 'Whisparr', $payload),
            'add_series' => $this->add($this->actionTargets->sonarrLookup($this->id($type, $payload, 'tvdb_id'), $payload, $fallbackName), ServiceType::Sonarr, $payload),
            'add_movie' => $this->add($this->actionTargets->radarrLookup($this->id($type, $payload, 'tmdb_id'), $payload, $fallbackName), ServiceType::Radarr, $payload),
            'whisparr_add_item' => $this->add($this->actionTargets->whisparrLookup($this->id($type, $payload, 'tmdb_id'), $payload, $fallbackName), ServiceType::Whisparr, $payload),
            'monitor_series' => $this->monitor($this->actionTargets->sonarrSeries($this->id($type, $payload, 'series_id'), $payload, $fallbackName), 'Sonarr', $payload),
            'monitor_movie' => $this->monitor($this->actionTargets->radarrMovie($this->id($type, $payload, 'movie_id'), $payload, $fallbackName), 'Radarr', $payload),
            'whisparr_monitor_item' => $this->monitor($this->actionTargets->whisparrItem($this->id($type, $payload, 'whisparr_item_id'), $payload, $fallbackName), 'Whisparr', $payload),
            'set_series_quality_profile' => $this->qualityProfile($this->actionTargets->sonarrSeries($this->id($type, $payload, 'series_id'), $payload, $fallbackName), ServiceType::Sonarr, $payload),
            'set_movie_quality_profile' => $this->qualityProfile($this->actionTargets->radarrMovie($this->id($type, $payload, 'movie_id'), $payload, $fallbackName), ServiceType::Radarr, $payload),
            'whisparr_set_quality_profile' => $this->qualityProfile($this->actionTargets->whisparrItem($this->id($type, $payload, 'whisparr_item_id'), $payload, $fallbackName), ServiceType::Whisparr, $payload),
            'approve_seerr_request' => $this->seerr($this->actionTargets->seerrRequest($this->id($type, $payload, 'seerr_request_id'), $payload, $fallbackName), 'Approve', 'Seerr will approve the request and send it to Sonarr or Radarr.'),
            'decline_seerr_request' => $this->seerr($this->actionTargets->seerrRequest($this->id($type, $payload, 'seerr_request_id'), $payload, $fallbackName), 'Decline', 'Seerr will decline the request.'),
            'cleanup_seerr_request' => $this->seerr($this->actionTargets->seerrRequest($this->id($type, $payload, 'seerr_request_id'), $payload, $fallbackName), 'Clean up', 'Seerr will delete the request.'),
            'emby_library_scan' => $this->libraryScan(),
            'remove_stuck_download' => $this->removeStuckDownload($type, $payload, $fallbackName),
            'resolve_manual_import' => $this->resolveManualImport($type, $payload, $fallbackName),
            default => throw UndescribableAction::unsupportedType($type),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function delete(ActionTarget $target, string $service, array $payload): ActionDescription
    {
        $deleteFiles = (bool) ($payload['delete_files'] ?? false);

        return $target->describe(
            sprintf('Delete %s', $target->label()),
            $deleteFiles
                ? sprintf('%s will delete the %s and its files from disk.', $service, $target->noun)
                : sprintf('%s will remove the %s but keep its files on disk.', $service, $target->noun),
        )->withDetail('Delete files', $deleteFiles);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function add(ActionTarget $target, ServiceType $serviceType, array $payload): ActionDescription
    {
        $profileId = (int) ($payload['quality_profile_id'] ?? 0);
        $actionDescription = $target->describe(
            sprintf('Add %s', $target->label()),
            sprintf('%s will add the %s and search for it.', $this->serviceName($serviceType), $target->noun),
        )
            ->withDetail('Quality profile', $this->actionTargets->qualityProfileName($serviceType, $profileId, $payload) ?? sprintf('#%d', $profileId))
            ->withDetail('Root folder', is_string($payload['root_folder_path'] ?? null) ? $payload['root_folder_path'] : null)
            ->withDetail('Monitored', (bool) ($payload['monitored'] ?? true));

        return $serviceType === ServiceType::Sonarr
            ? $actionDescription->withDetail('Season folders', (bool) ($payload['season_folder'] ?? true))
            : $actionDescription;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function monitor(ActionTarget $target, string $service, array $payload): ActionDescription
    {
        $monitored = (bool) ($payload['monitored'] ?? true);

        return $target->describe(
            sprintf('%s %s', $monitored ? 'Monitor' : 'Unmonitor', $target->label()),
            sprintf('%s will %s monitoring the %s.', $service, $monitored ? 'start' : 'stop', $target->noun),
        )->withDetail('Monitored', $monitored);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function qualityProfile(ActionTarget $target, ServiceType $serviceType, array $payload): ActionDescription
    {
        $profileId = (int) ($payload['quality_profile_id'] ?? 0);
        $profile = $this->actionTargets->qualityProfileName($serviceType, $profileId, $payload) ?? sprintf('#%d', $profileId);

        return $target->describe(
            sprintf('Change quality profile of %s', $target->label()),
            sprintf('%s will switch the %s to the "%s" quality profile.', $this->serviceName($serviceType), $target->noun, $profile),
        )->withDetail('Quality profile', $profile);
    }

    private function seerr(ActionTarget $target, string $verb, string $effect): ActionDescription
    {
        return $target->describe(sprintf('%s Seerr request for "%s"', $verb, $target->name), $effect);
    }

    private function libraryScan(): ActionDescription
    {
        $actionTarget = $this->actionTargets->embyLibrary();

        return $actionTarget->describe(
            'Scan the Emby library',
            sprintf('Emby server "%s" will rescan its libraries to pick up changes.', $actionTarget->name),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function removeStuckDownload(string $type, array $payload, ?string $fallbackName): ActionDescription
    {
        $serviceType = $this->downloadService($type, $payload);
        $actionTarget = $this->actionTargets->download($serviceType, $this->downloadId($type, $payload), $payload, $fallbackName);
        $blocklist = ($payload['blocklist'] ?? false) === true;
        $searchReplacement = ($payload['search_replacement'] ?? false) === true;

        return $actionTarget->describe(
            sprintf('Remove stuck %s', $actionTarget->label()),
            sprintf(
                '%s will remove the download from its queue and delete its data%s%s.',
                $this->serviceName($serviceType),
                $blocklist ? ', blocklist the release' : '',
                $searchReplacement ? ', then search for a replacement' : '',
            ),
        )
            ->withDetail('Blocklist release', $blocklist)
            ->withDetail('Search for replacement', $searchReplacement);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveManualImport(string $type, array $payload, ?string $fallbackName): ActionDescription
    {
        $serviceType = $this->downloadService($type, $payload);
        $actionTarget = $this->actionTargets->download($serviceType, $this->downloadId($type, $payload), $payload, $fallbackName);
        $assessment = is_array($payload['assessment'] ?? null) ? $payload['assessment'] : [];
        $importable = (int) ($assessment['importable'] ?? 0);
        $total = (int) ($assessment['total'] ?? 0);
        $reasons = is_array($assessment['reasons'] ?? null) ? array_filter($assessment['reasons'], is_string(...)) : [];

        return $actionTarget->describe(
            sprintf('Import %s', $actionTarget->label()),
            sprintf('%s will import the %d of %d files it could match.', $this->serviceName($serviceType), $importable, $total),
        )
            ->withDetail('Importable files', sprintf('%d of %d', $importable, $total))
            ->withDetail('Fully matched', ($assessment['fully_mapped'] ?? false) === true)
            ->withDetail('Notes', $reasons === [] ? null : implode(' ', $reasons));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function id(string $type, array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        $id = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;

        throw_if($id <= 0, UndescribableAction::missingTarget($type, $key));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function downloadService(string $type, array $payload): ServiceType
    {
        $serviceType = ServiceType::tryFrom((string) ($payload['service'] ?? ''));

        throw_unless(in_array($serviceType, [ServiceType::Sonarr, ServiceType::Radarr], true), UndescribableAction::missingTarget($type, 'service'));

        return $serviceType;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function downloadId(string $type, array $payload): string
    {
        $downloadId = $payload['download_id'] ?? null;

        throw_unless(is_string($downloadId) && $downloadId !== '', UndescribableAction::missingTarget($type, 'download_id'));

        return $downloadId;
    }

    private function serviceName(ServiceType $serviceType): string
    {
        return ucfirst($serviceType->value);
    }
}
