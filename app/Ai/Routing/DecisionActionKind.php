<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\Tools\Arr\GetMediaTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Ai\Tools\Emby\WatchHistoryTool;
use App\Ai\Tools\Seerr\ListPendingRequestsTool;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Ai\Tools\System\QueryActivityTool;
use App\Concerns\EnumUtils;

/**
 * The kind of operator action a webhook event most likely needs. The
 * decision gate's classification call picks one, and a confident pick loads
 * only that kind's tools into the DecisionAgent.
 */
enum DecisionActionKind: string
{
    use EnumUtils;

    case LibraryChange = 'library_change';
    case SeerrRequest = 'seerr_request';
    case MediaServer = 'media_server';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LibraryChange => 'Library change',
            self::SeerrRequest => 'Seerr request',
            self::MediaServer => 'Media server',
            self::Other => 'Other',
        };
    }

    /**
     * The option text the classifier chooses between.
     */
    public function description(): string
    {
        return match ($this) {
            self::LibraryChange => 'Add, monitor, change the quality profile of, or delete a series or movie in Sonarr/Radarr.',
            self::SeerrRequest => 'Approve, decline or clean up a Seerr media request.',
            self::MediaServer => 'Scan the Emby library or act on playback.',
            self::Other => 'Something else, or unclear.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function choiceOptions(): array
    {
        return array_combine(
            array_map(static fn (self $kind): string => $kind->value, self::cases()),
            array_map(static fn (self $kind): string => $kind->description(), self::cases()),
        );
    }

    /**
     * The kind's tools on top of the core, or null for every tool.
     *
     * @return list<class-string>|null
     */
    public function toolClasses(): ?array
    {
        return match ($this) {
            self::LibraryChange => [SearchMediaTool::class, GetMediaTool::class],
            self::SeerrRequest => [ListPendingRequestsTool::class],
            self::MediaServer => [NowPlayingTool::class, WatchHistoryTool::class],
            self::Other => null,
        };
    }

    /**
     * Tools every scoped run keeps.
     *
     * @return list<class-string>
     */
    public static function coreTools(): array
    {
        return [GetServiceStatusTool::class, QueryActivityTool::class, ProposeActionTool::class];
    }

    /**
     * The kind an action type belongs to, or null for types the
     * DecisionAgent cannot propose through ProposeActionTool.
     */
    public static function forActionType(string $type): ?self
    {
        return match ($type) {
            'add_series', 'monitor_series', 'set_series_quality_profile', 'delete_series',
            'add_movie', 'monitor_movie', 'set_movie_quality_profile', 'delete_movie' => self::LibraryChange,
            'approve_seerr_request', 'decline_seerr_request', 'cleanup_seerr_request' => self::SeerrRequest,
            'emby_library_scan' => self::MediaServer,
            default => null,
        };
    }
}
