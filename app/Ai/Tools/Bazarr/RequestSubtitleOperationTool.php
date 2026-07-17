<?php

declare(strict_types=1);

namespace App\Ai\Tools\Bazarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\SubtitleInventoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;

final class RequestSubtitleOperationTool extends BaseTool
{
    private const array OPERATIONS = [
        'download_best',
        'download_exact',
        'delete_subtitle',
        'sync_subtitle',
        'translate_subtitle',
        'scan_media',
    ];

    public function description(): string
    {
        return 'Queue one Bazarr subtitle operation through the Action Request approval rules. Re-inspects the target '
            .'and re-fetches exact candidates or subtitle tracks server-side. Never accepts a path, URL, or raw subtitle.';
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $arguments = $request->toArray();
        $connection = $this->connection((int) ($arguments['bazarr_connection_id'] ?? 0));
        $mediaType = (string) ($arguments['media_type'] ?? '');
        $mediaId = (int) ($arguments['media_id'] ?? 0);
        $operation = (string) ($arguments['operation'] ?? '');

        throw_unless(in_array($operation, self::OPERATIONS, true), InvalidArgumentException::class, 'Unsupported Bazarr operation.');

        $inspection = resolve(SubtitleInventoryService::class)->inspect($connection, $mediaType, $mediaId);
        $item = $inspection['item'];
        $bazarrClient = new BazarrClient($connection);
        $operationPayload = $this->operationPayload($operation, $arguments, $item, $bazarrClient, $mediaType, $mediaId);
        $managingConnection = $this->managingConnection($connection, $mediaType);

        return [
            'type' => 'bazarr_'.$operation,
            'source_service' => 'ai',
            'target_service' => ServiceType::Bazarr->value,
            'payload' => [
                'title' => sprintf('%s for %s', str_replace('_', ' ', ucfirst($operation)), (string) ($item['title'] ?? 'media')),
                'bazarr_connection_id' => $connection->id,
                'service_connection_id' => $managingConnection->id,
                'media_type' => $mediaType,
                'target_ids' => $mediaType === 'episode'
                    ? ['series_id' => (int) ($item['series_id'] ?? 0), 'episode_id' => $mediaId]
                    : ['radarr_id' => $mediaId],
                'target_fingerprint' => (string) ($item['target_fingerprint'] ?? ''),
                ...$operationPayload,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function operationPayload(
        string $operation,
        array $arguments,
        array $item,
        BazarrClient $bazarrClient,
        string $mediaType,
        int $mediaId,
    ): array {
        if ($operation === 'download_best') {
            $language = mb_strtolower(trim((string) ($arguments['language'] ?? '')));
            throw_unless(preg_match('/^[a-z]{2,3}(?:-[a-z0-9]+)?$/D', $language) === 1, InvalidArgumentException::class, 'A valid language is required.');

            return [
                'language' => $language,
                'forced' => ($arguments['forced'] ?? false) === true,
                'hearing_impaired' => ($arguments['hearing_impaired'] ?? false) === true,
            ];
        }

        if ($operation === 'download_exact') {
            $fingerprint = (string) ($arguments['candidate_fingerprint'] ?? '');
            $candidates = $mediaType === 'episode'
                ? $bazarrClient->searchEpisode($mediaId)
                : $bazarrClient->searchMovie($mediaId);

            throw_unless(
                collect($candidates)->contains('fingerprint', $fingerprint),
                InvalidArgumentException::class,
                'The selected subtitle candidate is no longer available.',
            );

            return ['candidate_fingerprint' => $fingerprint];
        }

        if (in_array($operation, ['delete_subtitle', 'sync_subtitle', 'translate_subtitle'], true)) {
            $fingerprint = (string) ($arguments['subtitle_fingerprint'] ?? '');

            throw_unless(
                collect($item['subtitle_tracks'] ?? [])->contains('fingerprint', $fingerprint),
                InvalidArgumentException::class,
                'The selected subtitle track is no longer available.',
            );

            return ['subtitle_fingerprint' => $fingerprint];
        }

        $mediaAction = (string) ($arguments['media_action'] ?? '');
        throw_unless(
            in_array($mediaAction, ['scan-disk', 'search-missing', 'search-wanted', 'sync'], true),
            InvalidArgumentException::class,
            'A valid media action is required.',
        );

        return ['media_action' => $mediaAction];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'bazarr_connection_id' => $schema->integer()->description('The exact active Bazarr connection ID.')->required(),
            'media_type' => $schema->string()->enum(['episode', 'movie'])->description('The exact media type.')->required(),
            'media_id' => $schema->integer()->description('The exact Sonarr episode ID or Radarr movie ID.')->required(),
            'operation' => $schema->string()->enum(self::OPERATIONS)->description('The operation to queue.')->required(),
            'language' => $schema->string()->description('Language for download_best, otherwise null.')->required()->nullable(),
            'forced' => $schema->boolean()->description('Forced flag for download_best, otherwise null.')->required()->nullable(),
            'hearing_impaired' => $schema->boolean()->description('Hearing-impaired flag for download_best, otherwise null.')->required()->nullable(),
            'candidate_fingerprint' => $schema->string()->description('Opaque candidate fingerprint for download_exact, otherwise null.')->required()->nullable(),
            'subtitle_fingerprint' => $schema->string()->description('Opaque track fingerprint for delete, sync, or translate; otherwise null.')->required()->nullable(),
            'media_action' => $schema->string()->enum(['scan-disk', 'search-missing', 'search-wanted', 'sync'])->description('Action for scan_media, otherwise null.')->required()->nullable(),
        ];
    }

    private function connection(int $connectionId): ServiceConnection
    {
        return ServiceConnection::query()
            ->whereKey($connectionId)
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function managingConnection(ServiceConnection $serviceConnection, string $mediaType): ServiceConnection
    {
        $role = $mediaType === 'episode' ? BazarrServiceRole::Sonarr : BazarrServiceRole::Radarr;
        $connection = $serviceConnection->mappedConnection($role);

        throw_unless(
            $connection instanceof ServiceConnection
                && $connection->is_active
                && $connection->type === $role->serviceType(),
            InvalidArgumentException::class,
            'The managing service connection is missing or inactive.',
        );

        return $connection;
    }
}
