<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ChatTemplateVariableType;
use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use LogicException;

/**
 * Library lookups for series/movie template variables. Always reads the
 * active connection of the matching type, the same one every Arr tool
 * resolves, so a picked id is one the agent can act on.
 */
final class ChatTemplateLibrary
{
    public const int SEARCH_LIMIT = 10;

    public function activeConnection(ChatTemplateVariableType $chatTemplateVariableType): ?ServiceConnection
    {
        return ServiceConnection::query()
            ->where('type', $this->serviceType($chatTemplateVariableType))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<array{id: int, title: string, year: int|null, poster_url: string|null}>
     */
    public function search(ChatTemplateVariableType $chatTemplateVariableType, string $term): array
    {
        $serviceConnection = $this->activeConnection($chatTemplateVariableType);

        if (! $serviceConnection instanceof ServiceConnection) {
            return [];
        }

        $pattern = sprintf('%%%s%%', addcslashes($term, '%_\\'));

        if ($chatTemplateVariableType === ChatTemplateVariableType::Series) {
            return IndexedSeries::query()
                ->where('service_connection_id', $serviceConnection->id)
                ->whereLike('title', $pattern)
                ->orderBy('title')
                ->limit(self::SEARCH_LIMIT)
                ->get(['sonarr_id', 'title', 'year', 'poster_url'])
                ->map(static fn (IndexedSeries $indexedSeries): array => [
                    'id' => $indexedSeries->sonarr_id,
                    'title' => $indexedSeries->title,
                    'year' => $indexedSeries->year,
                    'poster_url' => $indexedSeries->poster_url,
                ])
                ->values()
                ->all();
        }

        return IndexedMovie::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->whereLike('title', $pattern)
            ->orderBy('title')
            ->limit(self::SEARCH_LIMIT)
            ->get(['radarr_id', 'title', 'year', 'poster_url'])
            ->map(static fn (IndexedMovie $indexedMovie): array => [
                'id' => $indexedMovie->radarr_id,
                'title' => $indexedMovie->title,
                'year' => $indexedMovie->year,
                'poster_url' => $indexedMovie->poster_url,
            ])
            ->values()
            ->all();
    }

    public function find(ChatTemplateVariableType $chatTemplateVariableType, int $id): ?ChatTemplateLibraryTitle
    {
        $serviceConnection = $this->activeConnection($chatTemplateVariableType);

        if (! $serviceConnection instanceof ServiceConnection) {
            return null;
        }

        $row = $chatTemplateVariableType === ChatTemplateVariableType::Series
            ? IndexedSeries::query()->where('service_connection_id', $serviceConnection->id)->where('sonarr_id', $id)->first(['title', 'year'])
            : IndexedMovie::query()->where('service_connection_id', $serviceConnection->id)->where('radarr_id', $id)->first(['title', 'year']);

        return $row === null ? null : new ChatTemplateLibraryTitle($chatTemplateVariableType, $row->title, $row->year, $id);
    }

    private function serviceType(ChatTemplateVariableType $chatTemplateVariableType): ServiceType
    {
        return match ($chatTemplateVariableType) {
            ChatTemplateVariableType::Series => ServiceType::Sonarr,
            ChatTemplateVariableType::Movie => ServiceType::Radarr,
            default => throw new LogicException(sprintf('%s variables are not library variables.', $chatTemplateVariableType->label())),
        };
    }
}
