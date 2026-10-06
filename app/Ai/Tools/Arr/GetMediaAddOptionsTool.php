<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrConnections;
use App\Services\Whisparr\WhisparrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetMediaAddOptionsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List the quality profiles (id + name) and root folders (path + free space in bytes) of Sonarr, '
            .'Radarr, or Whisparr. Call this before AddMediaTool or SetMediaQualityProfileTool to pick a valid '
            .'quality_profile_id and root_folder_path instead of guessing.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array{quality_profiles: list<array{id: int, name: string}>, root_folders: list<array{path: string, free_space: ?int}>}
     */
    protected function execute(Request $request): array
    {
        $validated = $request->validate([
            'service' => ['required', 'string', Rule::in(['sonarr', 'radarr', 'whisparr'])],
        ]);

        $serviceType = ServiceType::from((string) $validated['service']);
        $serviceConnection = ServiceConnection::resolveActive($serviceType);
        $client = $serviceType === ServiceType::Whisparr
            ? new WhisparrClient($serviceConnection)
            : resolve(ArrConnections::class)->client($serviceConnection);

        return [
            'quality_profiles' => array_map(static fn (array $profile): array => [
                'id' => (int) $profile['id'],
                'name' => (string) ($profile['name'] ?? ''),
            ], array_values($client->getQualityProfiles())),
            'root_folders' => array_map(static fn (array $folder): array => [
                'path' => (string) ($folder['path'] ?? ''),
                'free_space' => isset($folder['freeSpace']) ? (int) $folder['freeSpace'] : null,
            ], array_values($client->getRootFolders())),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->enum(['sonarr', 'radarr', 'whisparr'])
                ->description('Which service to read: sonarr for TV series, radarr for movies, whisparr.')
                ->required(),
        ];
    }
}
