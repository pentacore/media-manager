<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Cache\Services\ConnectionScopedCache;
use App\Enums\MediaSearchCommand;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\MediaReplacement\PendingReplacementGuard;
use App\Services\MediaReplacement\ReplacementInFlight;
use App\Support\PayloadInt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The executor bodies Sonarr and Radarr share: delete, add, monitor and
 * quality-profile writes on one series/movie, plus search_media and
 * grab_release (reached through ArrActions). A subclass names its payload
 * keys and bridges to its client's series/movie methods; Sonarr adds
 * monitor_episodes, monitor_season and its episode-ownership check.
 *
 * Connection pinning per action is deliberate: the library writes use
 * resolvePinned() (an unpinned request may run against the active
 * connection), search_media and grab_release use resolvePinnedStrict()
 * (their ids are meaningless on another instance). Writes start from an
 * uncached read, never a cached snapshot.
 *
 * @template TClient of ArrClient
 */
abstract class ArrLibraryActions implements ActionExecutor
{
    /**
     * Defaults keep `new SonarrActions` / `new RadarrActions` (used
     * throughout the tests) working; the container still injects when
     * resolving.
     */
    public function __construct(
        protected readonly PendingReplacementGuard $pendingReplacementGuard = new PendingReplacementGuard,
        protected readonly ReleaseGrabber $releaseGrabber = new ReleaseGrabber,
        protected readonly SearchCommandRunner $searchCommandRunner = new SearchCommandRunner,
    ) {}

    abstract protected function serviceType(): ServiceType;

    /** The item noun in action types and messages: "series" or "movie". */
    abstract protected function itemNoun(): string;

    /** The delete payload's id key, also every library-write result's id key ("sonarr_series_id"). */
    abstract protected function libraryIdKey(): string;

    /** The monitor/profile payload's id key ("series_id"). */
    abstract protected function itemIdKey(): string;

    /** The add payload's external id key ("tvdb_id"). */
    abstract protected function externalIdKey(): string;

    /** The upstream lookup term for an add ("tvdb:123"). */
    abstract protected function lookupTerm(int $externalId): string;

    /**
     * @return TClient
     */
    abstract protected function client(ServiceConnection $serviceConnection): ArrClient;

    abstract protected function cache(ServiceConnection $serviceConnection): ConnectionScopedCache;

    /**
     * @param  TClient  $client
     * @return array<int, array<string, mixed>>
     */
    abstract protected function lookup(ArrClient $client, string $term): array;

    /**
     * @param  TClient  $client
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    abstract protected function add(ArrClient $client, array $item): array;

    /**
     * An uncached read for a write path: a PUT must start from what the
     * service holds now, never from a cached snapshot.
     *
     * @param  TClient  $client
     * @return array<string, mixed>
     */
    abstract protected function fetchFresh(ArrClient $client, int $itemId): array;

    /**
     * @param  TClient  $client
     * @param  array<string, mixed>  $item
     */
    abstract protected function update(ArrClient $client, int $itemId, array $item): void;

    /**
     * @param  TClient  $client
     */
    abstract protected function delete(ArrClient $client, int $itemId, bool $deleteFiles): void;

    /**
     * The service's own add fields, merged after the shared ones.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    abstract protected function addOptions(array $payload): array;

    abstract protected function replacementInFlight(int $serviceConnectionId, int $itemId): bool;

    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $itemNoun = $this->itemNoun();

        return match ($actionRequest->type) {
            sprintf('delete_%s', $itemNoun) => $this->deleteItem($payload),
            sprintf('add_%s', $itemNoun) => $this->addItem($payload),
            sprintf('monitor_%s', $itemNoun) => $this->monitorItem($payload),
            sprintf('set_%s_quality_profile', $itemNoun) => $this->setQualityProfile($payload),
            'search_media' => $this->searchMedia($payload),
            'grab_release' => $this->grabRelease($payload),
            default => $this->executeOther($actionRequest),
        };
    }

    /**
     * Action types only one service has (Sonarr's monitor_episodes, monitor_season).
     *
     * @return array<string, mixed>
     */
    protected function executeOther(ActionRequest $actionRequest): array
    {
        throw new InvalidArgumentException(sprintf('%s cannot execute type "%s"', class_basename($this), $actionRequest->type));
    }

    /**
     * Refuse a search whose ids do not belong together before anything is
     * sent. Nothing to check by default; Sonarr checks episode searches.
     *
     * @param  TClient  $client
     * @param  array<string, mixed>  $payload
     */
    protected function assertSearchTargets(ArrClient $client, MediaSearchCommand $mediaSearchCommand, array $payload): void {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function deleteItem(array $payload): array
    {
        $itemId = PayloadInt::required($payload, $this->libraryIdKey());
        $deleteFiles = (bool) ($payload['delete_files'] ?? false);

        $serviceConnection = ServiceConnection::resolvePinned($payload, $this->serviceType());
        $this->delete($this->client($serviceConnection), $itemId, $deleteFiles);
        $this->cache($serviceConnection)->bustAll();

        return [
            $this->libraryIdKey() => $itemId,
            'delete_files' => $deleteFiles,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addItem(array $payload): array
    {
        $externalId = PayloadInt::required($payload, $this->externalIdKey());

        $serviceConnection = ServiceConnection::resolvePinned($payload, $this->serviceType());
        $client = $this->client($serviceConnection);

        // Look up the full spec by its external id (the arr lookup accepts "tvdb:{id}" / "tmdb:{id}").
        $candidates = $this->lookup($client, $this->lookupTerm($externalId));

        throw_if($candidates === [], InvalidArgumentException::class, sprintf(
            'No %s found in %s lookup for %s %d',
            $this->itemNoun(),
            $this->serviceType()->label(),
            $this->externalIdKey(),
            $externalId,
        ));

        $item = $this->add($client, array_merge($candidates[0], [
            'qualityProfileId' => (int) ($payload['quality_profile_id'] ?? 0),
            'rootFolderPath' => (string) ($payload['root_folder_path'] ?? ''),
            'monitored' => (bool) ($payload['monitored'] ?? true),
            ...$this->addOptions($payload),
        ]));

        $this->cache($serviceConnection)->bustAll();

        return [
            $this->libraryIdKey() => $item['id'] ?? null,
            'title' => $item['title'] ?? null,
            $this->externalIdKey() => $externalId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function monitorItem(array $payload): array
    {
        $itemId = PayloadInt::required($payload, $this->itemIdKey());
        $monitored = (bool) ($payload['monitored'] ?? true);

        $serviceConnection = ServiceConnection::resolvePinned($payload, $this->serviceType());

        throw_if($this->replacementInFlight($serviceConnection->id, $itemId), ReplacementInFlight::forTitle());

        $client = $this->client($serviceConnection);
        $item = $this->fetchFresh($client, $itemId);
        $item['monitored'] = $monitored;
        $this->update($client, $itemId, $item);
        $this->cache($serviceConnection)->bustAll();

        return [
            $this->libraryIdKey() => $itemId,
            'monitored' => $monitored,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function setQualityProfile(array $payload): array
    {
        $itemId = PayloadInt::required($payload, $this->itemIdKey());
        $qualityProfileId = PayloadInt::required($payload, 'quality_profile_id');

        $serviceConnection = ServiceConnection::resolvePinned($payload, $this->serviceType());
        $client = $this->client($serviceConnection);
        $item = $this->fetchFresh($client, $itemId);
        $item['qualityProfileId'] = $qualityProfileId;
        $this->update($client, $itemId, $item);
        $this->cache($serviceConnection)->bustAll();

        return [
            $this->libraryIdKey() => $itemId,
            'quality_profile_id' => $qualityProfileId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function searchMedia(array $payload): array
    {
        $command = MediaSearchCommand::tryFrom((string) ($payload['command'] ?? ''));
        $label = $this->serviceType()->label();

        throw_unless(
            $command instanceof MediaSearchCommand && $command->service() === $this->serviceType(),
            InvalidArgumentException::class,
            sprintf('command is not a %s search', $label),
        );

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, $this->serviceType());
        $client = $this->client($serviceConnection);
        $this->assertSearchTargets($client, $command, $payload);

        $response = $this->searchCommandRunner->run($client, $label, $command, $command->arrParameters($payload));

        return [
            'command' => $command->value,
            'arr_command_id' => $response['id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function grabRelease(array $payload): array
    {
        $guid = (string) ($payload['guid'] ?? '');
        $indexerId = (int) ($payload['indexer_id'] ?? 0);

        throw_if($guid === '' || $indexerId <= 0, InvalidArgumentException::class, 'guid and indexer_id are required');

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, $this->serviceType());
        $label = $this->serviceType()->label();
        $this->releaseGrabber->grab($this->client($serviceConnection), $label, $guid, $indexerId);

        try {
            $this->cache($serviceConnection)->bustAll();
        } catch (Throwable $throwable) {
            // The grab already succeeded upstream — a stale cache is a
            // read-freshness problem, not a reason to report the grab as
            // failed (which would leave the member thinking nothing happened).
            Log::warning(sprintf('%s: failed to bust the %s cache after a successful grab', class_basename($this), $label), [
                'service_connection_id' => $serviceConnection->id,
                'guid' => $guid,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }

        return [
            'indexer_id' => $indexerId,
            'title' => is_string($payload['release']['title'] ?? null) ? $payload['release']['title'] : null,
        ];
    }
}
