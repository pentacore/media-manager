<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

final readonly class EmbyHistoryBackfiller
{
    public function __construct(private EmbyClient $embyClient) {}

    public function backfillUser(
        EmbyUserLink $embyUserLink,
        int $pageSize = 500,
        ?DateTimeInterface $since = null,
        bool $dryRun = false,
        ?Closure $onProgress = null,
    ): BackfillResult {
        $itemsFetched = 0;
        $itemsCreated = 0;
        $itemsUpdated = 0;
        $itemsSkipped = 0;
        $startIndex = 0;

        do {
            $params = [
                'IncludeItemTypes' => 'Movie,Episode',
                'Recursive' => 'true',
                'Filters' => 'IsPlayed',
                'Fields' => 'UserData,RunTimeTicks,SeriesName',
                'SortBy' => 'DatePlayed',
                'SortOrder' => 'Descending',
                'StartIndex' => $startIndex,
                'Limit' => $pageSize,
            ];
            if ($since instanceof DateTimeInterface) {
                $params['MinDateLastSaved'] = CarbonImmutable::instance($since)->utc()->format('Y-m-d\TH:i:s.000\Z');
            }

            $response = $this->embyClient->getUserItems($embyUserLink->emby_user_id, $params);
            $items = $response['Items'] ?? [];
            $total = (int) ($response['TotalRecordCount'] ?? count($items));
            $pageCount = count($items);
            $itemsFetched += $pageCount;

            foreach ($items as $item) {
                $outcome = $this->processItem($embyUserLink, $item, $dryRun);
                match ($outcome) {
                    'created' => $itemsCreated++,
                    'updated' => $itemsUpdated++,
                    'skipped' => $itemsSkipped++,
                };
            }

            $startIndex += $pageCount;

            if ($onProgress instanceof Closure) {
                $onProgress($startIndex, $total);
            }
        } while ($pageCount === $pageSize && $startIndex < $total);

        return new BackfillResult($itemsFetched, $itemsCreated, $itemsUpdated, $itemsSkipped);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function processItem(EmbyUserLink $embyUserLink, array $item, bool $dryRun): string
    {
        $type = $item['Type'] ?? null;
        $mediaType = match ($type) {
            'Movie' => 'movie',
            'Episode' => 'episode',
            default => null,
        };
        if ($mediaType === null) {
            return 'skipped';
        }

        $userData = $item['UserData'] ?? [];
        $played = ($userData['Played'] ?? false) === true;
        $position = isset($userData['PlaybackPositionTicks']) ? (int) $userData['PlaybackPositionTicks'] : 0;

        $action = match (true) {
            $played => 'finished',
            $position > 0 => 'stopped',
            default => null,
        };
        if ($action === null) {
            return 'skipped';
        }

        $lastPlayed = $userData['LastPlayedDate'] ?? null;
        $timestamp = is_string($lastPlayed) && $lastPlayed !== ''
            ? CarbonImmutable::parse($lastPlayed)
            : CarbonImmutable::now();

        $itemId = (string) ($item['Id'] ?? '');
        if ($itemId === '') {
            return 'skipped';
        }

        $attributes = [
            'media_type' => $mediaType,
            'media_title' => $item['Name'] ?? null,
            'series_title' => $item['SeriesName'] ?? null,
            'action' => $action,
            'duration_ticks' => isset($item['RunTimeTicks']) ? (int) $item['RunTimeTicks'] : null,
            'play_position' => $position,
        ];

        if ($dryRun) {
            $exists = EmbyActivity::query()
                ->where('emby_user_link_id', $embyUserLink->id)
                ->where('emby_item_id', $itemId)
                ->whereNull('play_session_id')
                ->exists();

            return $exists ? 'updated' : 'created';
        }

        return Model::withoutTimestamps(function () use ($embyUserLink, $itemId, $attributes, $timestamp): string {
            $activity = EmbyActivity::query()
                ->where('emby_user_link_id', $embyUserLink->id)
                ->where('emby_item_id', $itemId)
                ->whereNull('play_session_id')
                ->first();

            $isNew = $activity === null;
            if ($isNew) {
                $activity = new EmbyActivity;
                $activity->emby_user_link_id = $embyUserLink->id;
                $activity->emby_item_id = $itemId;
                $activity->play_session_id = null;
            }

            $activity->fill($attributes);
            $activity->created_at = $timestamp;
            $activity->updated_at = $timestamp;
            $activity->save();

            return $isNew ? 'created' : 'updated';
        });
    }
}
